<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\SalesMetrics;
use Illuminate\Console\Command;

/**
 * Rebuilds the materialised product sales metrics from order history.
 */
class RebuildSalesMetrics extends Command
{
    protected $signature = 'metrics:rebuild';

    protected $description = 'Recompute product sales totals and ranking from order_items';

    /**
     * Rebuild every row and report how long it took.
     */
    public function handle(SalesMetrics $metrics): int
    {
        $this->info('Rebuilding product sales metrics…');
        $startedAt = microtime(true);

        $metrics->rebuild();

        $this->info(sprintf('Done in %.1fs.', microtime(true) - $startedAt));

        return self::SUCCESS;
    }
}
