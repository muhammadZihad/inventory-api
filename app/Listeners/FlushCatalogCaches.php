<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Catalog\CatalogChanged;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;

/**
 * Invalidates cached catalog reads after a product or category write.
 */
class FlushCatalogCaches
{
    /**
     * Bind the shared cache repository.
     */
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Flush product and category caches after a catalog change.
     */
    public function handle(CatalogChanged $event): void
    {
        // Category payloads carry product counts and product payloads carry the
        // category, so either side of the relationship invalidates both.
        $this->cache->flush(CacheNamespace::Products, CacheNamespace::Categories);
    }
}
