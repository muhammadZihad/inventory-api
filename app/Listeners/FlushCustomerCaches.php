<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Customers\CustomerChanged;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;

/**
 * Invalidates cached customer reads after a customer write.
 */
class FlushCustomerCaches
{
    /**
     * Bind the shared cache repository.
     */
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Flush the customer cache namespace.
     */
    public function handle(CustomerChanged $event): void
    {
        $this->cache->flush(CacheNamespace::Customers);
    }
}
