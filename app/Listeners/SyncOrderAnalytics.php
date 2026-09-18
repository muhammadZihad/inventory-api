<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderCreated;
use App\Events\Orders\OrderStatusChanged;
use App\Jobs\RefreshOrderReportCache;

/**
 * Pushes report recalculation off the request path after an order write.
 */
class SyncOrderAnalytics
{
    /**
     * Queue a report cache refresh once the write transaction has committed.
     */
    public function handle(OrderCreated|OrderStatusChanged|OrderCancelled $event): void
    {
        RefreshOrderReportCache::dispatch()->afterCommit();
    }
}
