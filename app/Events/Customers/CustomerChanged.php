<?php

declare(strict_types=1);

namespace App\Events\Customers;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a customer record is created, updated, or deleted.
 */
class CustomerChanged
{
    use Dispatchable;

    /**
     * Carry the customer that changed.
     */
    public function __construct(public readonly ?string $customerId = null) {}
}
