<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Types of entries written to the inventory movement ledger.
 *
 * Every change to on-hand or reserved stock writes exactly one movement, so
 * the ledger can be replayed to reconcile the balances on inventory_items.
 */
enum InventoryMovementType: string
{
    /** Stock committed to an order but not yet shipped. */
    case OrderReserved = 'order_reserved';

    /** A reservation given back after an order is cancelled. */
    case ReservationReleased = 'reservation_released';

    /** A reservation converted into a physical stock decrement on fulfilment. */
    case OrderFulfilled = 'order_fulfilled';

    /** Stock added by receiving new goods. */
    case Restock = 'restock';

    /** A manual correction, positive or negative, for shrinkage or recounts. */
    case Correction = 'correction';

    /**
     * Return the movement types a client may submit through the adjust endpoint.
     *
     * Order-driven movements are written by the order workflow only.
     *
     * @return array<int, string>
     */
    public static function manualValues(): array
    {
        return [self::Restock->value, self::Correction->value];
    }
}
