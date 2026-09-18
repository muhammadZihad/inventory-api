<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The cache namespaces used for read-through caching and bulk invalidation.
 *
 * Each namespace owns a monotonically increasing version counter. Bumping the
 * counter orphans every key written under the previous version, which gives
 * "flush this group" semantics on any cache driver, including ones without
 * tag support.
 */
enum CacheNamespace: string
{
    case Products = 'products';
    case Categories = 'categories';
    case Inventory = 'inventory';
    case Customers = 'customers';
    case Reports = 'reports';
}
