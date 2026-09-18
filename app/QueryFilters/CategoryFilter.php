<?php

declare(strict_types=1);

namespace App\QueryFilters;

/**
 * Defines searchable, filterable, and sortable fields for categories.
 */
class CategoryFilter extends QueryFilter
{
    protected array $searchColumns = ['name', 'slug'];

    protected array $exactFilters = [
        'status' => 'status',
    ];

    protected array $sortableColumns = ['name', 'slug', 'status', 'products_count', 'created_at'];

    protected bool $supportsCreatedAtRange = true;
}
