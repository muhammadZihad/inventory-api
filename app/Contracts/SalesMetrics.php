<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Order;
use App\Models\Product;

/**
 * Maintains the materialised per-product sales aggregates.
 *
 * Separating the contract from the SQL implementation means the listener and
 * the rebuild command depend on the behaviour, not on the fact that it is
 * currently a MySQL-compatible table.
 */
interface SalesMetrics
{
    /**
     * Create the zeroed row a new product needs so it still sorts and joins.
     */
    public function initialise(Product $product): void;

    /**
     * Fold a newly created order into the running totals.
     */
    public function applyOrder(Order $order): void;

    /**
     * Rebuild every total from order history, then re-rank.
     */
    public function rebuild(): void;

    /**
     * Recompute the global sales ranking.
     */
    public function refreshRanks(): void;
}
