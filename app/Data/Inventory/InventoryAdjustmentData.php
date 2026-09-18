<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\InventoryMovementType;

/**
 * Carries validated stock adjustment inputs into inventory actions.
 */
class InventoryAdjustmentData
{
    /**
     * Create an inventory adjustment from a movement type and a signed quantity.
     *
     * A positive quantity adds stock, a negative one removes it.
     */
    public function __construct(
        public readonly InventoryMovementType $type,
        public readonly int $quantity,
    ) {}

    /**
     * Build the DTO from validated request data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: InventoryMovementType::from($data['type']),
            quantity: (int) $data['quantity'],
        );
    }
}
