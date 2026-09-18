<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\OrderReports;
use App\Contracts\SalesMetrics;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuilds the cached order summary and the product sales ranking away from
 * the request path.
 *
 * The listener that dispatches this job has already invalidated the report
 * cache, so this job re-warms the unfiltered summary that dashboards hit most
 * often. It is unique for a short window so a burst of orders results in one
 * recalculation rather than one per order.
 */
class RefreshOrderReportCache implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Seconds this job stays unique while queued. */
    public int $uniqueFor = 60;

    /** Number of attempts before the job is marked failed. */
    public int $tries = 3;

    /**
     * Invalidate the stale summary and recompute it for the next reader.
     */
    public function handle(OrderReports $reports, CacheRepository $cache, SalesMetrics $metrics): void
    {
        $cache->flush(CacheNamespace::Reports);

        $reports->summary();

        // Sales totals are updated synchronously on the write path, but a rank
        // is global: one sale can move every other product. Recomputing it here
        // keeps that cost off the request, and ShouldBeUnique collapses a burst
        // of orders into a single pass.
        $metrics->refreshRanks();
    }
}
