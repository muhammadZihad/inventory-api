<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Orders\OrderCreated;
use App\Services\SalesMetricsService;

/**
 * Folds a new order into the materialised product sales totals.
 *
 * Runs synchronously so the totals are correct the moment the order response
 * is returned. Only order creation matters: the aggregates are derived from
 * order_items, which never change once written, so later status transitions
 * leave them untouched.
 */
class UpdateProductSalesMetrics
{
    /**
     * Bind the metrics service.
     */
    public function __construct(private readonly SalesMetricsService $metrics) {}

    /**
     * Add the order's line items to their products' totals.
     */
    public function handle(OrderCreated $event): void
    {
        $this->metrics->applyOrder($event->order);
    }
}
