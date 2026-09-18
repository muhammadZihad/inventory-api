<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the materialised per-product sales aggregates.
 *
 * The totals are exact and updated synchronously: they depend only on
 * order_items, which are written once when an order is created and never
 * change afterwards, so an order can simply be added to the running totals.
 *
 * The rank is different — one product's sale can shift every other product's
 * position — so it is recomputed in a background job rather than on the write
 * path. It therefore trails the totals by at most one job cycle.
 */
class SalesMetricsService
{
    /**
     * Create the zeroed row a new product needs so it still sorts and joins.
     */
    public function initialise(Product $product): void
    {
        DB::table('product_sales_metrics')->insertOrIgnore([
            'product_id' => $product->id,
            'units_sold' => 0,
            'orders_count' => 0,
            'gross_sales' => 0,
            'sales_rank' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Fold a newly created order into the running totals.
     */
    public function applyOrder(Order $order): void
    {
        // Read through the query builder rather than the relation: OrderItem
        // casts money attributes to integer cents, but this table stores the
        // same dollars the order_items column holds.
        $lines = DB::table('order_items')
            ->where('order_id', $order->getKey())
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) AS quantity, SUM(line_total) AS line_total')
            ->get();

        foreach ($lines as $line) {
            // The row may not exist yet for products created before this table,
            // so it is seeded at zero and then incremented. incrementEach()
            // builds a single portable UPDATE with bound values.
            DB::table('product_sales_metrics')->insertOrIgnore([
                'product_id' => $line->product_id,
                'units_sold' => 0,
                'orders_count' => 0,
                'gross_sales' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('product_sales_metrics')
                ->where('product_id', $line->product_id)
                ->incrementEach([
                    'units_sold' => (int) $line->quantity,
                    'orders_count' => 1,
                    'gross_sales' => (float) $line->line_total,
                ], ['updated_at' => now()]);
        }
    }

    /**
     * Rebuild every total from order_items, then re-rank.
     *
     * Used by the seeder and by the rebuild command; also the repair path if
     * the incremental totals are ever suspected of drifting.
     */
    public function rebuild(): void
    {
        DB::table('product_sales_metrics')->delete();

        // One INSERT ... SELECT keeps the whole rebuild inside the database.
        DB::statement('
            INSERT INTO product_sales_metrics
                (product_id, units_sold, orders_count, gross_sales, sales_rank, created_at, updated_at)
            SELECT
                p.id,
                COALESCE(t.units_sold, 0),
                COALESCE(t.orders_count, 0),
                COALESCE(t.gross_sales, 0),
                NULL,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM products p
            LEFT JOIN (
                SELECT
                    product_id,
                    SUM(quantity) AS units_sold,
                    COUNT(DISTINCT order_id) AS orders_count,
                    COALESCE(SUM(line_total), 0) AS gross_sales
                FROM order_items
                GROUP BY product_id
            ) t ON t.product_id = p.id
        ');

        $this->refreshRanks();
    }

    /**
     * Recompute the global sales ranking.
     *
     * Products with no sales are left unranked rather than all sharing last
     * place, which matches what the API returned before the table existed.
     */
    public function refreshRanks(): void
    {
        // Written as a correlated subquery over a derived table rather than an
        // UPDATE ... JOIN: SQLite has no update-join, and the derived table is
        // what lets MySQL read the table it is updating.
        DB::statement('
            UPDATE product_sales_metrics
            SET sales_rank = (
                SELECT position FROM (
                    SELECT product_id, DENSE_RANK() OVER (ORDER BY gross_sales DESC) AS position
                    FROM product_sales_metrics
                    WHERE gross_sales > 0
                ) AS ranked
                WHERE ranked.product_id = product_sales_metrics.product_id
            )
        ');
    }
}
