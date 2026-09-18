<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Data\Catalog\CategoryData;
use App\Events\Catalog\CatalogChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryIndexRequest;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;
use Illuminate\Http\JsonResponse;

/**
 * Product category APIs with cached reads.
 *
 * @group Categories
 */
class CategoryController extends Controller
{
    /**
     * Bind the read-through cache.
     */
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * List categories
     *
     * Search, filter, sort, and paginate product categories. Responses are
     * cached and invalidated by any catalog write.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": [
     *     {
     *       "id": "01m2swhmj7dps93xge6qjdzjae",
     *       "name": "Peripherals",
     *       "slug": "peripherals",
     *       "status": "active",
     *       "products_count": 12
     *     }
     *   ],
     *   "meta": {"current_page": 1, "per_page": 15, "total": 1, "last_page": 1, "from": 1, "to": 1}
     * }
     * @response 401 scenario="Unauthenticated" {"success": false, "message": "Unauthenticated.", "errors": null}
     */
    public function index(CategoryIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $payload = $this->cache->remember(
            CacheNamespace::Categories,
            ['view' => 'categories.index', 'page' => $request->integer('page', 1), 'per_page' => $request->perPage(), ...$filters],
            fn (): array => ApiResponse::paginatedPayload(
                Category::query()->withCount('products')->filter($filters)->paginate($request->perPage()),
                CategoryResource::class,
            ),
        );

        return ApiResponse::fromPayload($payload);
    }

    /**
     * Create category
     *
     * Store a new product category.
     *
     * @authenticated
     *
     * @response 201 scenario="Created" {
     *   "success": true,
     *   "message": "Category created successfully.",
     *   "data": {"id": "01m2swhmj7dps93xge6qjdzjae", "name": "Peripherals", "slug": "peripherals", "status": "active", "products_count": 0}
     * }
     * @response 422 scenario="Validation error" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"name": ["The name field is required."]}
     * }
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $category = Category::query()->create(
            CategoryData::fromArray($request->validated())->toCreateAttributes(),
        );

        CatalogChanged::dispatch('category', $category->id);

        return ApiResponse::created(
            CategoryResource::make($category->loadCount('products'))->resolve(),
            'Category created successfully.',
        );
    }

    /**
     * Show category
     *
     * Return one product category with its product count.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "OK.",
     *   "data": {"id": "01m2swhmj7dps93xge6qjdzjae", "name": "Peripherals", "slug": "peripherals", "status": "active", "products_count": 12}
     * }
     * @response 404 scenario="Not found" {"success": false, "message": "Resource not found.", "errors": null}
     */
    public function show(Category $category): JsonResponse
    {
        $payload = $this->cache->remember(
            CacheNamespace::Categories,
            ['view' => 'categories.show', 'id' => $category->id],
            fn (): array => CategoryResource::make($category->loadCount('products'))->resolve(),
        );

        return ApiResponse::success($payload, 'OK.');
    }

    /**
     * Update category
     *
     * Update the supplied fields for an existing category.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "Category updated successfully.",
     *   "data": {"id": "01m2swhmj7dps93xge6qjdzjae", "name": "Input Devices", "slug": "input-devices", "status": "active", "products_count": 12}
     * }
     * @response 422 scenario="Validation error" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"slug": ["The slug has already been taken."]}
     * }
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $category->update(
            CategoryData::fromArray($request->validated())->toUpdateAttributes($category),
        );

        CatalogChanged::dispatch('category', $category->id);

        return ApiResponse::success(
            CategoryResource::make($category->fresh()->loadCount('products'))->resolve(),
            'Category updated successfully.',
        );
    }

    /**
     * Delete category
     *
     * Remove a category from the catalog.
     *
     * @authenticated
     *
     * @response 200 scenario="Deleted" {"success": true, "message": "Category deleted successfully.", "data": null}
     * @response 404 scenario="Not found" {"success": false, "message": "Resource not found.", "errors": null}
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $category->delete();

        CatalogChanged::dispatch('category', $category->id);

        return ApiResponse::success(null, 'Category deleted successfully.');
    }
}
