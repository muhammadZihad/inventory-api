<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderCreated;
use App\Events\Orders\OrderStatusChanged;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;

/**
 * Invalidates every cached read that an order write can make stale.
 *
 * Runs synchronously on purpose: deferring invalidation to a queue worker
 * would leave a window where reads are served from a cache the write has
 * already contradicted.
 */
class FlushOrderCaches
{
    /**
     * Bind the shared cache repository.
     */
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Flush the namespaces whose contents depend on order state.
     */
    public function handle(OrderCreated|OrderStatusChanged|OrderCancelled $event): void
    {
        // Orders drive the report aggregates, the reserved/available balances
        // on inventory, the sales metrics attached to products, and the order
        // totals attached to customers.
        $this->cache->flush(
            CacheNamespace::Reports,
            CacheNamespace::Inventory,
            CacheNamespace::Products,
            CacheNamespace::Customers,
        );
    }
}
