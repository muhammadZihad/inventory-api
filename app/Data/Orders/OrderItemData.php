<?php

declare(strict_types=1);

namespace App\Data\Orders;

/**
 * Represents one product quantity requested for an order.
 */
class OrderItemData
{
    /**
     * Create an order item data object for one product quantity.
     */
    public function __construct(
        public readonly string $productId,
        public readonly int $quantity,
    ) {}
}
