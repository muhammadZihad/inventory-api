<?php

declare(strict_types=1);

namespace App\Data\Orders;

use Illuminate\Support\Collection;

/**
 * Carries validated order creation payloads and item DTOs.
 */
class CreateOrderData
{
    /**
     * @param  Collection<int, OrderItemData>  $items
     */
    public function __construct(
        public readonly string $customerId,
        public readonly Collection $items,
    ) {}

    /**
     * Build the DTO from validated request data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customerId: $data['customer_id'],
            items: collect($data['items'])->map(
                fn (array $item) => new OrderItemData($item['product_id'], (int) $item['quantity'])
            ),
        );
    }
}
