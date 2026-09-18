<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\CreateProductAction;
use App\Data\Catalog\ProductData;
use App\Events\Catalog\CatalogChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductIndexRequest;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Queries\ProductListQuery;
use App\Support\ApiResponse;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

/**
 * Product catalog APIs with cached reads and sales metrics.
 *
 * @group Products
 */
class ProductController extends Controller
{
    /**
     * Bind the read-through cache.
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ProductListQuery $products,
    ) {}

    /**
     * List products
     *
     * Search, filter by status, category, and price range, sort on any
     * whitelisted column, and paginate. Responses are cached per unique set of
     * query parameters and invalidated by any catalog or stock write.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": [
     *     {
     *       "id": "01m2swhmj7dps93xge6qjdzjaf",
     *       "category_id": "01m2swhmj7dps93xge6qjdzjae",
     *       "category_name": "Peripherals",
     *       "name": "Mechanical Keyboard",
     *       "sku": "KB-001",
     *       "price": "125.00",
     *       "status": "active",
     *       "stock": 50,
     *       "available_stock": 42,
     *       "units_sold": 8,
     *       "gross_sales": "1000.00",
     *       "sales_rank": 1,
     *       "created_at": "2026-09-18T10:00:00.000000Z"
     *     }
     *   ],
     *   "meta": {"current_page": 1, "per_page": 15, "total": 1, "last_page": 1, "from": 1, "to": 1}
     * }
     * @response 422 scenario="Invalid filter" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"max_price": ["The max price field must be greater than or equal to min price."]}
     * }
     */
    public function index(ProductIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $payload = $this->cache->remember(
            CacheNamespace::Products,
            ['view' => 'products.index', 'page' => $request->integer('page', 1), 'per_page' => $request->perPage(), ...$filters],
            fn (): array => ApiResponse::paginatedPayload(
                $this->products->paginate($filters, $request->perPage()),
                ProductResource::class,
            ),
        );

        return ApiResponse::fromPayload($payload);
    }

    /**
     * Create product
     *
     * Create a product and its opening inventory balance.
     *
     * @authenticated
     *
     * @response 201 scenario="Created" {
     *   "success": true,
     *   "message": "Product created successfully.",
     *   "data": {
     *     "id": "01m2swhmj7dps93xge6qjdzjaf",
     *     "name": "Mechanical Keyboard",
     *     "sku": "KB-001",
     *     "price": "125.00",
     *     "status": "active",
     *     "stock": 50,
     *     "available_stock": 50
     *   }
     * }
     * @response 422 scenario="Validation error" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"sku": ["The sku has already been taken."]}
     * }
     */
    public function store(StoreProductRequest $request, CreateProductAction $action): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = $action->execute(ProductData::fromArray($request->validated()));

        return ApiResponse::created($this->present($product->id), 'Product created successfully.');
    }

    /**
     * Show product
     *
     * Return one product with its category, inventory, and sales metrics.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": {
     *     "id": "01m2swhmj7dps93xge6qjdzjaf",
     *     "name": "Mechanical Keyboard",
     *     "sku": "KB-001",
     *     "price": "125.00",
     *     "status": "active",
     *     "stock": 50,
     *     "available_stock": 42
     *   }
     * }
     * @response 404 scenario="Not found" {"success": false, "message": "Resource not found.", "errors": null}
     */
    public function show(Product $product): JsonResponse
    {
        $payload = $this->cache->remember(
            CacheNamespace::Products,
            ['view' => 'products.show', 'id' => $product->id],
            fn (): array => $this->present($product->id),
        );

        return ApiResponse::success($payload, 'OK.');
    }

    /**
     * Update product
     *
     * Update the supplied product fields. Prices are accepted in dollars and
     * normalized through integer cents before storage.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "Product updated successfully.",
     *   "data": {"id": "01m2swhmj7dps93xge6qjdzjaf", "name": "Mechanical Keyboard", "price": "149.00", "status": "active"}
     * }
     * @response 422 scenario="Validation error" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"price": ["The price field must have 0-2 decimal places."]}
     * }
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $attributes = collect($request->validated())
            ->when(
                $request->has('price'),
                fn ($data) => $data->put('price', Money::dollarsToCents($request->validated('price'))),
            )
            ->all();

        $product->update($attributes);

        CatalogChanged::dispatch('product', $product->id);

        return ApiResponse::success($this->present($product->id), 'Product updated successfully.');
    }

    /**
     * Delete product
     *
     * Remove a product from the catalog.
     *
     * @authenticated
     *
     * @response 200 scenario="Deleted" {"success": true, "message": "Product deleted successfully.", "data": null}
     * @response 404 scenario="Not found" {"success": false, "message": "Resource not found.", "errors": null}
     */
    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        CatalogChanged::dispatch('product', $product->id);

        return ApiResponse::success(null, 'Product deleted successfully.');
    }

    /**
     * Resolve one product into its response payload.
     *
     * @return array<string, mixed>
     */
    private function present(string $productId): array
    {
        return ProductResource::make(
            $this->products->baseQuery()->withSalesMetrics()->findOrFail($productId),
        )->resolve();
    }
}
