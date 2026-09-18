<?php

declare(strict_types=1);

namespace App\Events\Inventory;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever on-hand or reserved stock changes for one or more products.
 */
class InventoryChanged
{
    use Dispatchable;

    /**
     * Carry the affected product identifiers.
     *
     * @param  array<int, string>  $productIds
     */
    public function __construct(public readonly array $productIds) {}
}
