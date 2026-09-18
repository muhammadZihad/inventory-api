<?php

declare(strict_types=1);

namespace App\Events\Orders;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after an order is cancelled and its reservations are released.
 */
class OrderCancelled
{
    use Dispatchable, SerializesModels;

    /**
     * Carry the cancelled order.
     */
    public function __construct(public readonly Order $order) {}
}
