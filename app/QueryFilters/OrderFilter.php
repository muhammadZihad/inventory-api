<?php

declare(strict_types=1);

namespace App\QueryFilters;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Defines searchable, filterable, sortable, and range fields for orders.
 */
class OrderFilter extends QueryFilter
{
    protected array $searchColumns = ['order_number'];

    protected array $exactFilters = [
        'status' => 'status',
        'customer_id' => 'customer_id',
    ];

    protected array $sortableColumns = ['order_number', 'status', 'customer_name', 'total_amount', 'items_count', 'created_at'];

    protected ?string $moneyRangeColumn = 'total_amount';

    protected string $moneyRangePrefix = 'total';

    protected bool $supportsCreatedAtRange = true;

    /**
     * Apply order sorting, including readable customer name and item count columns.
     */
    protected function applySort(Builder $query): void
    {
        $sort = $this->filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, $this->sortableColumns, true)) {
            $column = 'created_at';
            $direction = 'desc';
        }

        if ($column === 'customer_name') {
            $query->orderBy(
                Customer::query()
                    ->select('name')
                    ->whereColumn('customers.id', 'orders.customer_id')
                    ->limit(1),
                $direction
            );

            return;
        }

        $query->orderBy($column, $direction);
    }
}
