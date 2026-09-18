<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasFilters;
use App\QueryFilters\CustomerFilter;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'phone', 'created_by', 'updated_by'])]
/**
 * Represents a customer who can place orders.
 */
class Customer extends BaseModel
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasFilters, HasUlids;

    protected string $filterClass = CustomerFilter::class;

    /**
     * Attach order totals calculated through a CTE and customer value rank window function.
     */
    /** Columns whose values come from the global order aggregate. */
    public const ORDER_METRIC_COLUMNS = ['orders_count', 'completed_orders_count', 'total_order_amount', 'customer_value_rank'];

    /**
     * Fetch order metrics for a specific set of customers.
     *
     * Attached after a page has been selected so the aggregate is never joined
     * to the whole customer table, and the paginator's count query stays a
     * plain indexed count.
     *
     * @param  array<int, string>  $customerIds
     * @return array<string, array{orders_count: int, completed_orders_count: int, total_order_amount: string, customer_value_rank: int|null}>
     */
    public static function orderMetricsFor(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return self::orderMetricsSubquery()
            ->whereIn('customer_id', $customerIds)
            ->get()
            ->keyBy('customer_id')
            ->map(fn ($row): array => [
                'orders_count' => (int) $row->orders_count,
                'completed_orders_count' => (int) $row->completed_orders_count,
                'total_order_amount' => $row->total_order_amount,
                'customer_value_rank' => $row->customer_value_rank === null ? null : (int) $row->customer_value_rank,
            ])
            ->all();
    }

    /**
     * Build the ranked order aggregate shared by both access paths.
     */
    private static function orderMetricsSubquery(): QueryBuilder
    {
        return DB::query()->fromRaw("(
            WITH customer_order_totals AS (
                SELECT
                    customer_id,
                    COUNT(*) AS orders_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders_count,
                    COALESCE(SUM(total_amount), 0) AS total_order_amount
                FROM orders
                GROUP BY customer_id
            ),
            ranked_customer_totals AS (
                SELECT
                    customer_id,
                    orders_count,
                    completed_orders_count,
                    total_order_amount,
                    DENSE_RANK() OVER (ORDER BY total_order_amount DESC) AS customer_value_rank
                FROM customer_order_totals
            )
            SELECT * FROM ranked_customer_totals
        ) AS customer_order_metrics");
    }

    /**
     * Attach order totals and customer value rank.
     *
     * Only needed when the client sorts by one of the metric columns, because
     * sorting has to happen before pagination.
     */
    public function scopeWithOrderMetrics(Builder $query): Builder
    {
        $metrics = self::orderMetricsSubquery();

        return $query
            ->select('customers.*')
            ->leftJoinSub($metrics, 'customer_order_metrics', 'customer_order_metrics.customer_id', '=', 'customers.id')
            ->addSelect([
                DB::raw('COALESCE(customer_order_metrics.orders_count, 0) AS orders_count'),
                DB::raw('COALESCE(customer_order_metrics.completed_orders_count, 0) AS completed_orders_count'),
                DB::raw('COALESCE(customer_order_metrics.total_order_amount, 0) AS total_order_amount'),
                DB::raw('customer_order_metrics.customer_value_rank AS customer_value_rank'),
            ]);
    }

    /**
     * Get orders placed by this customer.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
