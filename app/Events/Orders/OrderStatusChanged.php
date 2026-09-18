<?php

declare(strict_types=1);

namespace App\Events\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after an order moves between workflow states.
 */
class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    /**
     * Carry the order and both sides of the transition.
     */
    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {}
}
