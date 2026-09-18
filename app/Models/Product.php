<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\SalesMetrics;
use App\Models\Concerns\HasFilters;
use App\QueryFilters\ProductFilter;
use App\Support\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

#[Fillable(['category_id', 'name', 'sku', 'description', 'price', 'status', 'created_by', 'updated_by'])]
/**
 * Represents a catalog product with price, category, and inventory links.
 */
class Product extends BaseModel
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasFilters, HasUlids;

    protected string $filterClass = ProductFilter::class;

    /**
     * Guarantee every product has a sales metrics row.
     *
     * Catalog reads join that table, so a product without a row would be
     * invisible to them. Hooking the model event rather than the create action
     * makes the invariant hold for every product written through Eloquent,
     * including factories and console scripts. Bulk inserts that bypass
     * Eloquent are covered by `php artisan metrics:rebuild`.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::created(function (self $product): void {
            app(SalesMetrics::class)->initialise($product);
        });
    }

    /** Columns whose values come from the global sales aggregate. */
    public const SALES_METRIC_COLUMNS = ['units_sold', 'orders_count', 'gross_sales', 'sales_rank'];

    /**
     * Fetch sales metrics for a specific set of products.
     *
     * Used after a page has been selected, so the expensive aggregate is joined
     * to the handful of rows being returned rather than to the whole catalog.
     * The ranking still has to be computed across all products — a rank is
     * meaningless otherwise — but it is paid once per request instead of twice
     * (the paginator's count query no longer carries the join) and nothing is
     * sorted or hydrated beyond the current page.
     *
     * @param  array<int, string>  $productIds
     * @return array<string, array{units_sold: int, orders_count: int, gross_sales: string, sales_rank: int|null}>
     */
    public static function salesMetricsFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return self::salesMetricsSubquery()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id')
            ->map(fn ($row): array => [
                'units_sold' => (int) $row->units_sold,
                'orders_count' => (int) $row->orders_count,
                'gross_sales' => $row->gross_sales,
                'sales_rank' => $row->sales_rank === null ? null : (int) $row->sales_rank,
            ])
            ->all();
    }

    /**
     * Build the metrics source shared by both access paths.
     *
     * Reads the materialised table rather than aggregating order_items, so a
     * metric sort is an index range scan instead of a filesort over a join.
     */
    private static function salesMetricsSubquery(): QueryBuilder
    {
        return DB::table('product_sales_metrics');
    }

    /**
     * Attach sales totals and rank from the materialised metrics table.
     *
     * Only needed when the client sorts by one of the metric columns, because
     * sorting has to happen before pagination.
     *
     * The join is inner, not left, and that is what makes a metric sort cheap:
     * every product has exactly one metrics row (created with the product and
     * rebuilt by `metrics:rebuild`), so the two are equivalent in result, but
     * an inner join lets the optimiser drive from the sorted metric index and
     * read fifteen rows instead of sorting the whole catalog.
     */
    public function scopeWithSalesMetrics(Builder $query): Builder
    {
        return $query
            ->select('products.*')
            ->join('product_sales_metrics', 'product_sales_metrics.product_id', '=', 'products.id')
            ->addSelect([
                DB::raw('COALESCE(product_sales_metrics.units_sold, 0) AS units_sold'),
                DB::raw('COALESCE(product_sales_metrics.orders_count, 0) AS orders_count'),
                DB::raw('COALESCE(product_sales_metrics.gross_sales, 0) AS gross_sales'),
                DB::raw('product_sales_metrics.sales_rank AS sales_rank'),
            ]);
    }

    /**
     * Get the materialised sales metrics row for this product.
     */
    public function salesMetrics(): HasOne
    {
        return $this->hasOne(ProductSalesMetric::class);
    }

    /**
     * Convert product price between stored dollars and server-side cents.
     */
    protected function price(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Money::dollarsToCents($value),
            set: fn ($value) => Money::centsToDollars($value),
        );
    }

    /**
     * Get the category this product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the current inventory balance for this product.
     */
    public function inventory(): HasOne
    {
        return $this->hasOne(InventoryItem::class);
    }

    /**
     * Get inventory movement records for this product.
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
