<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Inventory\InventoryChanged;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;

/**
 * Invalidates cached inventory balances and the product reads that embed them.
 */
class FlushInventoryCaches
{
    /**
     * Bind the shared cache repository.
     */
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Flush inventory and product caches after a stock change.
     */
    public function handle(InventoryChanged $event): void
    {
        // Product payloads embed the stock balance, so both namespaces go.
        $this->cache->flush(CacheNamespace::Inventory, CacheNamespace::Products);
    }
}
