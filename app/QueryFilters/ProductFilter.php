<?php

declare(strict_types=1);

namespace App\QueryFilters;

use App\Models\Category;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Defines the searchable, filterable, and sortable fields for products.
 */
class ProductFilter extends QueryFilter
{
    protected array $searchColumns = ['name', 'sku', 'description'];

    protected array $exactFilters = [
        'status' => 'status',
        'category_id' => 'category_id',
    ];

    /** Enables the validated min_price / max_price filters. */
    protected ?string $moneyRangeColumn = 'price';

    protected bool $supportsCreatedAtRange = true;

    protected array $sortableColumns = [
        'name',
        'sku',
        'status',
        'price',
        'category_name',
        'stock',
        'available_stock',
        'units_sold',
        'gross_sales',
        'sales_rank',
        'created_at',
    ];

    /**
     * Apply product filters including category name/slug relation search.
     */
    public function apply(Builder $query): Builder
    {
        parent::apply($query);

        if ($category = $this->filters['category'] ?? null) {
            $query->whereHas('category', function (Builder $query) use ($category): void {
                $query
                    ->where('name', 'like', '%'.$category.'%')
                    ->orWhere('slug', 'like', '%'.$category.'%');
            });
        }

        return $query;
    }

    /**
     * Apply product sorting, including category name and stock relation columns.
     */
    protected function applySortColumn(Builder $query, string $column, string $direction): void
    {
        $relationSort = match ($column) {
            'category_name' => Category::query()
                ->select('name')
                ->whereColumn('categories.id', 'products.category_id')
                ->limit(1),
            'stock' => InventoryItem::query()
                ->select('quantity_on_hand')
                ->whereColumn('inventory_items.product_id', 'products.id')
                ->limit(1),
            'available_stock' => InventoryItem::query()
                ->selectRaw(InventoryItem::AVAILABLE_EXPRESSION)
                ->whereColumn('inventory_items.product_id', 'products.id')
                ->limit(1),
            default => null,
        };

        if ($relationSort === null) {
            parent::applySortColumn($query, $column, $direction);

            return;
        }

        $query->orderBy($relationSort, $direction);
    }
}
