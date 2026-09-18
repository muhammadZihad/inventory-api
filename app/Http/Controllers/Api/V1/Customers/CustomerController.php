<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customers;

use App\Data\Customers\CustomerData;
use App\Events\Customers\CustomerChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerIndexRequest;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Queries\CustomerListQuery;
use App\Support\ApiResponse;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;
use Illuminate\Http\JsonResponse;

/**
 * Coordinates customer profile endpoints used by order processing.
 *
 * @group Customers
 */
class CustomerController extends Controller
{
    /**
     * Bind the read-through cache.
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly CustomerListQuery $customers,
    ) {}

    /**
     * List customers
     *
     * Search, filter, sort, and paginate customers with their order metrics.
     * Reads are cached and invalidated by customer and order writes.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": [
     *     {
     *       "id": "01m2swhmj7dps93xge6qjdzjae",
     *       "name": "Ada Lovelace",
     *       "email": "ada@example.com",
     *       "phone": "+1-555-0100",
     *       "orders_count": 4,
     *       "total_order_amount": "980.00"
     *     }
     *   ],
     *   "meta": {"current_page": 1, "per_page": 15, "total": 1, "last_page": 1, "from": 1, "to": 1}
     * }
     * @response 401 scenario="Unauthenticated" {"success": false, "message": "Unauthenticated.", "errors": null}
     */
    public function index(CustomerIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $payload = $this->cache->remember(
            CacheNamespace::Customers,
            ['view' => 'customers.index', 'page' => $request->integer('page', 1), 'per_page' => $request->perPage(), ...$filters],
            fn (): array => ApiResponse::paginatedPayload(
                $this->customers->paginate($filters, $request->perPage()),
                CustomerResource::class,
            ),
        );

        return ApiResponse::fromPayload($payload);
    }

    /**
     * Create customer
     *
     * Store a new customer profile for order placement.
     *
     * @authenticated
     *
     * @response 201 scenario="Created" {
     *   "success": true,
     *   "message": "Customer created successfully.",
     *   "data": {"id": "01m2swhmj7dps93xge6qjdzjae", "name": "Ada Lovelace", "email": "ada@example.com", "orders_count": 0, "total_order_amount": "0.00"}
     * }
     * @response 422 scenario="Validation error" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"email": ["The email has already been taken."]}
     * }
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = Customer::query()->create(
            CustomerData::fromArray($request->validated())->toCreateAttributes()
        );

        CustomerChanged::dispatch($customer->id);

        $customer = Customer::query()->withOrderMetrics()->findOrFail($customer->id);

        return ApiResponse::created(CustomerResource::make($customer)->resolve(), 'Customer created successfully.');
    }

    /**
     * Show customer
     *
     * Return one customer profile with its order metrics.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": {"id": "01m2swhmj7dps93xge6qjdzjae", "name": "Ada Lovelace", "email": "ada@example.com", "orders_count": 4, "total_order_amount": "980.00"}
     * }
     * @response 404 scenario="Not found" {"success": false, "message": "Resource not found.", "errors": null}
     */
    public function show(Customer $customer): JsonResponse
    {
        $payload = $this->cache->remember(
            CacheNamespace::Customers,
            ['view' => 'customers.show', 'id' => $customer->id],
            fn (): array => CustomerResource::make(
                Customer::query()->withOrderMetrics()->findOrFail($customer->id),
            )->resolve(),
        );

        return ApiResponse::success($payload, 'OK.');
    }

    /**
     * Update customer
     *
     * Update supplied fields for an existing customer profile.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "Customer updated successfully.",
     *   "data": {"id": "01m2swhmj7dps93xge6qjdzjae", "name": "Ada Lovelace", "email": "ada@example.com", "orders_count": 4, "total_order_amount": "980.00"}
     * }
     * @response 422 scenario="Validation error" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"email": ["The email has already been taken."]}
     * }
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $customer->update(
            CustomerData::fromArray($request->validated())->toUpdateAttributes($customer)
        );

        CustomerChanged::dispatch($customer->id);

        $customer = Customer::query()->withOrderMetrics()->findOrFail($customer->id);

        return ApiResponse::success(CustomerResource::make($customer)->resolve(), 'Customer updated successfully.');
    }

    /**
     * Delete customer
     *
     * Remove a customer profile.
     *
     * @authenticated
     *
     * @response 200 scenario="Deleted" {"success": true, "message": "Customer deleted successfully.", "data": null}
     * @response 404 scenario="Not found" {"success": false, "message": "Resource not found.", "errors": null}
     */
    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $customer->delete();

        CustomerChanged::dispatch($customer->id);

        return ApiResponse::success(null, 'Customer deleted successfully.');
    }
}
