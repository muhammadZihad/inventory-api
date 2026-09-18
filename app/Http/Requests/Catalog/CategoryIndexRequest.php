<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

/**
 * Validates category filtering, sorting, and pagination query parameters.
 */
class CategoryIndexRequest extends IndexRequest
{
    /**
     * Allow authenticated route middleware to decide access for this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return validation rules for category list query parameters.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['name', '-name', 'slug', '-slug', 'status', '-status', 'products_count', '-products_count', 'created_at', '-created_at'])],
            'per_page' => self::paginationRules(),
        ];
    }

    /**
     * Describe category list query parameters for Scribe examples.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'search' => ['description' => 'Filter: search category name or slug.', 'example' => 'electronics'],
            'status' => ['description' => 'Filter: category status. Allowed: active, archived.', 'example' => 'active'],
            'from' => ['description' => 'Filter: created_at from date, inclusive.', 'example' => '2026-09-01'],
            'to' => ['description' => 'Filter: created_at to date, inclusive.', 'example' => '2026-09-30'],
            'sort' => ['description' => 'Sortable: name, slug, status, products_count, created_at. Prefix with - for descending, for example -products_count.', 'example' => 'name'],
            ...$this->paginationQueryParameter('categories'),
        ];
    }
}
