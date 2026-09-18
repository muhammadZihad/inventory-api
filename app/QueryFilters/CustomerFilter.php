<?php

declare(strict_types=1);

namespace App\QueryFilters;

/**
 * Defines searchable and sortable fields for customers.
 */
class CustomerFilter extends QueryFilter
{
    protected array $searchColumns = ['name', 'email', 'phone'];

    protected array $sortableColumns = ['name', 'email', 'phone', 'orders_count', 'total_order_amount', 'customer_value_rank', 'created_at'];

    protected bool $supportsCreatedAtRange = true;
}
