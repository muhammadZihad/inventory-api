<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Actions\Inventory\AdjustInventoryAction;
use App\Data\Inventory\InventoryAdjustmentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustInventoryRequest;
use App\Http\Requests\Inventory\InventoryIndexRequest;
use App\Http\Requests\Inventory\InventoryMovementIndexRequest;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\InventoryMovementResource;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;
use Illuminate\Http\JsonResponse;

/**
 * Inventory balances, the movement ledger, and manual stock adjustments.
 *
 * @group Inventory
 */
class InventoryController extends Controller
{
    /**
     * Bind the read-through cache.
     */
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * List inventory
     *
     * Return paginated stock balances. `available_quantity` is on-hand minus
     * the units reserved by open orders, and is the figure a new order draws
     * from. Reads are served from cache and invalidated by any stock change.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": [
     *     {
     *       "id": "01m2swhmj7dps93xge6qjdzjai",
     *       "product_id": "01m2swhmj7dps93xge6qjdzjaf",
     *       "product_title": "Mechanical Keyboard",
     *       "quantity_on_hand": 50,
     *       "quantity_reserved": 8,
     *       "available_quantity": 42
     *     }
     *   ],
     *   "meta": {"current_page": 1, "per_page": 15, "total": 1, "last_page": 1, "from": 1, "to": 1}
     * }
     * @response 401 scenario="Unauthenticated" {"success": false, "message": "Unauthenticated.", "errors": null}
     */
    public function index(InventoryIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $payload = $this->cache->remember(
            CacheNamespace::Inventory,
            ['view' => 'inventory.index', 'page' => $request->integer('page', 1), 'per_page' => $request->perPage(), ...$filters],
            fn (): array => ApiResponse::paginatedPayload(
                InventoryItem::query()
                    ->with('product')
                    ->filter($filters)
                    ->paginate($request->perPage()),
                InventoryItemResource::class,
            ),
        );

        return ApiResponse::fromPayload($payload);
    }

    /**
     * Adjust inventory
     *
     * Apply a signed stock movement to a product. Restocks must be positive;
     * corrections may be negative to record shrinkage or a recount. An
     * adjustment is rejected if it would leave fewer units on hand than are
     * already reserved for open orders.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "Inventory adjusted successfully.",
     *   "data": {
     *     "id": "01m2swhmj7dps93xge6qjdzjai",
     *     "product_id": "01m2swhmj7dps93xge6qjdzjaf",
     *     "quantity_on_hand": 75,
     *     "quantity_reserved": 8,
     *     "available_quantity": 67
     *   }
     * }
     * @response 422 scenario="Would uncover reserved stock" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"quantity": ["This adjustment would leave 3 units on hand while 8 are reserved for open orders."]}
     * }
     */
    public function adjust(AdjustInventoryRequest $request, Product $product, AdjustInventoryAction $action): JsonResponse
    {
        $this->authorize('adjustInventory', $product);

        $inventory = $action->execute($product, InventoryAdjustmentData::fromArray($request->validated()));

        return ApiResponse::success(InventoryItemResource::make($inventory)->resolve(), 'Inventory adjusted successfully.');
    }

    /**
     * List stock movements
     *
     * Return the append-only ledger for one product, newest first. Every
     * reservation, release, fulfilment, restock, and correction appears here,
     * so the balances can be reconciled by replaying the ledger.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": [
     *     {
     *       "id": "01m2swhmj7dps93xge6qjdzjaj",
     *       "product_id": "01m2swhmj7dps93xge6qjdzjaf",
     *       "order_id": "01m2swhmj7dps93xge6qjdzjag",
     *       "type": "order_reserved",
     *       "quantity_delta": 0,
     *       "quantity_after": 50,
     *       "reserved_delta": 2,
     *       "reserved_after": 8,
     *       "created_at": "2026-09-18T10:30:00.000000Z"
     *     }
     *   ],
     *   "meta": {"current_page": 1, "per_page": 15, "total": 1, "last_page": 1, "from": 1, "to": 1}
     * }
     */
    public function movements(InventoryMovementIndexRequest $request, Product $product): JsonResponse
    {
        $movements = $product->inventoryMovements()
            ->when($request->validated('type'), fn ($query, string $type) => $query->where('type', $type))
            // ULIDs are time-ordered, so they break ties deterministically when
            // several movements land within the same second.
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return ApiResponse::paginated($movements, InventoryMovementResource::class);
    }
}
