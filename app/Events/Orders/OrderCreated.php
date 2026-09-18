<?php

declare(strict_types=1);

namespace App\Events\Orders;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after an order and its stock reservations are committed.
 */
class OrderCreated
{
    use Dispatchable, SerializesModels;

    /**
     * Carry the newly created order.
     */
    public function __construct(public readonly Order $order) {}
}
