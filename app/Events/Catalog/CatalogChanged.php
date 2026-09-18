<?php

declare(strict_types=1);

namespace App\Events\Catalog;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a product or category is created, updated, or deleted.
 */
class CatalogChanged
{
    use Dispatchable;

    /**
     * Carry the catalog entity that changed, for logging and future consumers.
     */
    public function __construct(public readonly string $entity, public readonly ?string $entityId = null) {}
}
