<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Orders;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Data\Orders\CreateOrderData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\OrderHistoryRequest;
use App\Http\Requests\Orders\OrderIndexRequest;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Requests\Orders\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderStatusHistoryResource;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Order processing APIs with stock reservation and retry-safe creation.
 *
 * @group Orders
 */
class OrderController extends Controller
{
    /**
     * List orders
     *
     * Return a paginated, filterable list of orders. Non-admin clients only
     * ever see the orders they created.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": [
     *     {
     *       "id": "01m2swhmj7dps93xge6qjdzjag",
     *       "customer_id": "01m2swhmj7dps93xge6qjdzjae",
     *       "customer_name": "Ada Lovelace",
     *       "order_number": "ORD-8F2K9QZ1MXTP",
     *       "status": "pending",
     *       "total_amount": "250.00",
     *       "items_count": 2,
     *       "cancelled_at": null,
     *       "created_at": "2026-09-18T10:30:00.000000Z"
     *     }
     *   ],
     *   "meta": {"current_page": 1, "per_page": 15, "total": 1, "last_page": 1, "from": 1, "to": 1}
     * }
     * @response 401 scenario="Unauthenticated" {"success": false, "message": "Unauthenticated.", "errors": null}
     */
    public function index(OrderIndexRequest $request): JsonResponse
    {
        $orders = Order::query()
            ->visibleTo($request->user())
            ->with(['customer', 'items.product'])
            ->withCount('items')
            ->filter($request->validated())
            ->paginate($request->perPage());

        return ApiResponse::paginated($orders, OrderResource::class);
    }

    /**
     * Create order
     *
     * Reserve stock and place an order. Requires an `Idempotency-Key` header:
     * retrying with the same key replays the original response instead of
     * placing a second order.
     *
     * @authenticated
     *
     * @header Idempotency-Key 6f1e1d1c-6b0a-4f2f-9a1e-8b0f5f2a1c3d
     *
     * @response 201 scenario="Created" {
     *   "success": true,
     *   "message": "Order created successfully.",
     *   "data": {
     *     "id": "01m2swhmj7dps93xge6qjdzjag",
     *     "customer_id": "01m2swhmj7dps93xge6qjdzjae",
     *     "order_number": "ORD-8F2K9QZ1MXTP",
     *     "status": "pending",
     *     "total_amount": "250.00",
     *     "items_count": 1,
     *     "cancelled_at": null,
     *     "created_at": "2026-09-18T10:30:00.000000Z"
     *   }
     * }
     * @response 409 scenario="Insufficient stock" {
     *   "success": false,
     *   "message": "Insufficient stock for one or more products.",
     *   "errors": {"items": [{"product_id": "01m2swhmj7dps93xge6qjdzjaf", "requested": 5, "available": 2}]}
     * }
     * @response 422 scenario="Missing idempotency key" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"Idempotency-Key": ["The Idempotency-Key header is required for this request."]}
     * }
     */
    public function store(StoreOrderRequest $request, CreateOrderAction $action): JsonResponse
    {
        $order = $action->execute(CreateOrderData::fromArray($request->validated()));

        return ApiResponse::created($this->present($order), 'Order created successfully.');
    }

    /**
     * Show order
     *
     * Return one order with its customer and line items.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": {
     *     "id": "01m2swhmj7dps93xge6qjdzjag",
     *     "order_number": "ORD-8F2K9QZ1MXTP",
     *     "status": "confirmed",
     *     "total_amount": "250.00",
     *     "items_count": 1,
     *     "cancelled_at": null,
     *     "created_at": "2026-09-18T10:30:00.000000Z"
     *   }
     * }
     * @response 403 scenario="Another client's order" {"success": false, "message": "This action is unauthorized.", "errors": null}
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success($this->present($order), 'OK.');
    }

    /**
     * Update order status
     *
     * Move an order to the next workflow status. Allowed transitions are
     * pending to confirmed and confirmed to completed; completing an order
     * converts its reservations into a physical stock decrement. Cancellation
     * is not accepted here — use the cancel endpoint so stock is released.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "Order status updated successfully.",
     *   "data": {"id": "01m2swhmj7dps93xge6qjdzjag", "status": "confirmed", "total_amount": "250.00"}
     * }
     * @response 422 scenario="Disallowed transition" {
     *   "success": false,
     *   "message": "An order cannot move from completed to confirmed.",
     *   "errors": {"status": ["An order cannot move from completed to confirmed."], "allowed_transitions": []}
     * }
     */
    public function status(UpdateOrderStatusRequest $request, Order $order, UpdateOrderStatusAction $action): JsonResponse
    {
        $this->authorize('update', $order);

        $order = $action->execute($order, $request->status(), $request->validated('note'));

        return ApiResponse::success($this->present($order), 'Order status updated successfully.');
    }

    /**
     * Cancel order
     *
     * Cancel an order and release its reserved stock back to available. Safe
     * to retry: cancelling an already-cancelled order releases nothing twice.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "Order cancelled successfully.",
     *   "data": {"id": "01m2swhmj7dps93xge6qjdzjag", "status": "cancelled", "total_amount": "250.00"}
     * }
     * @response 422 scenario="Already completed" {
     *   "success": false,
     *   "message": "An order cannot move from completed to cancelled.",
     *   "errors": {"status": ["An order cannot move from completed to cancelled."], "allowed_transitions": []}
     * }
     */
    public function cancel(Request $request, Order $order, CancelOrderAction $action): JsonResponse
    {
        $this->authorize('cancel', $order);

        $order = $action->execute($order, $request->user()->id);

        return ApiResponse::success($this->present($order), 'Order cancelled successfully.');
    }

    /**
     * Order status history
     *
     * Return the recorded status transitions for one order, newest first.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": [
     *     {
     *       "id": "01m2swhmj7dps93xge6qjdzjah",
     *       "order_id": "01m2swhmj7dps93xge6qjdzjag",
     *       "from_status": "pending",
     *       "to_status": "confirmed",
     *       "note": "Stock confirmed.",
     *       "created_at": "2026-09-18T10:35:00.000000Z"
     *     }
     *   ],
     *   "meta": {"current_page": 1, "per_page": 15, "total": 1, "last_page": 1, "from": 1, "to": 1}
     * }
     */
    public function history(OrderHistoryRequest $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $histories = $order->statusHistories()
            // ULIDs are time-ordered, so they break ties deterministically when
            // several transitions land within the same second.
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return ApiResponse::paginated($histories, OrderStatusHistoryResource::class);
    }

    /**
     * Load the relations every order response exposes and resolve the resource.
     *
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return OrderResource::make(
            $order->load(['customer', 'items.product'])->loadCount('items'),
        )->resolve();
    }
}
