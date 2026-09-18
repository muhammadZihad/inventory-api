<?php

declare(strict_types=1);

namespace App\QueryFilters;

use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Defines filterable and sortable fields for inventory balances.
 */
class InventoryItemFilter extends QueryFilter
{
    protected array $exactFilters = [
        'product_id' => 'product_id',
    ];

    protected array $sortableColumns = ['product_title', 'product_id', 'quantity_on_hand', 'quantity_reserved', 'available_quantity', 'created_at'];

    protected array $numericRangeFilters = [
        'quantity_on_hand' => ['min' => 'min_quantity_on_hand', 'max' => 'max_quantity_on_hand'],
        'quantity_reserved' => ['min' => 'min_quantity_reserved', 'max' => 'max_quantity_reserved'],
    ];

    protected bool $supportsCreatedAtRange = true;

    /**
     * Apply inventory filters including the derived availability range.
     */
    public function apply(Builder $query): Builder
    {
        parent::apply($query);

        if (array_key_exists('min_available_quantity', $this->filters)) {
            $query->whereRaw(InventoryItem::AVAILABLE_EXPRESSION.' >= ?', [(int) $this->filters['min_available_quantity']]);
        }

        if (array_key_exists('max_available_quantity', $this->filters)) {
            $query->whereRaw(InventoryItem::AVAILABLE_EXPRESSION.' <= ?', [(int) $this->filters['max_available_quantity']]);
        }

        return $query;
    }

    /**
     * Apply inventory sorting, including product title and calculated availability.
     */
    protected function applySortColumn(Builder $query, string $column, string $direction): void
    {
        if ($column === 'product_title') {
            $query->orderBy(
                Product::query()
                    ->select('name')
                    ->whereColumn('products.id', 'inventory_items.product_id')
                    ->limit(1),
                $direction
            );

            return;
        }

        if ($column === 'available_quantity') {
            // Availability is derived, so it is ordered by the same expression
            // the API exposes rather than by on-hand alone.
            $query->orderByRaw(InventoryItem::AVAILABLE_EXPRESSION.' '.($direction === 'desc' ? 'desc' : 'asc'));

            return;
        }

        parent::applySortColumn($query, $column, $direction);
    }
}
