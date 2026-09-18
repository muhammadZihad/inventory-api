<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

/**
 * Validates product filtering, sorting, and pagination query parameters.
 */
class ProductIndexRequest extends IndexRequest
{
    /**
     * Allow authenticated route middleware to decide access for this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return validation rules for this request payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'draft', 'archived'])],
            'category_id' => ['nullable', 'ulid', 'exists:categories,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            // gte is only applied when a lower bound was actually supplied,
            // otherwise "everything under $25" would fail validation.
            'max_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', Rule::when($this->filled('min_price'), ['gte:min_price'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['name', '-name', 'sku', '-sku', 'status', '-status', 'price', '-price', 'category_name', '-category_name', 'stock', '-stock', 'available_stock', '-available_stock', 'units_sold', '-units_sold', 'gross_sales', '-gross_sales', 'sales_rank', '-sales_rank', 'created_at', '-created_at'])],
            'per_page' => self::paginationRules(),
        ];
    }

    /**
     * Keep the range error field-oriented rather than echoing the bound value.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_price.gte' => 'The max price field must be greater than or equal to min price.',
        ];
    }

    /**
     * Describe product list query parameters for Scribe examples.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'search' => ['description' => 'Filter: search product name, SKU, or description.', 'example' => 'keyboard'],
            'status' => ['description' => 'Filter: product status. Allowed: active, draft, archived.', 'example' => 'active'],
            'category_id' => ['description' => 'Filter: category ULID.', 'example' => '01m2swhmj7dps93xge6qjdzjae'],
            'category' => ['description' => 'Filter: category name or slug.', 'example' => 'electronics'],
            'min_price' => ['description' => 'Filter: minimum product price in dollars.', 'example' => '10.00'],
            'max_price' => ['description' => 'Filter: maximum product price in dollars.', 'example' => '250.00'],
            'from' => ['description' => 'Filter: include products created on or after this date.', 'example' => '2026-01-01'],
            'to' => ['description' => 'Filter: include products created on or before this date.', 'example' => '2026-12-31'],
            'sort' => ['description' => 'Sortable: name, sku, status, price, category_name, stock, available_stock, units_sold, gross_sales, sales_rank, created_at. Prefix with - for descending, for example -price.', 'example' => '-price'],
            ...$this->paginationQueryParameter('products'),
        ];
    }
}
