<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\InventoryMovementType;
use App\Models\InventoryItem;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single writer for stock balances and the movement ledger.
 *
 * Depending on this contract rather than the Eloquent implementation keeps the
 * order actions testable in isolation and leaves room for a different backing
 * store — an external warehouse system, say — without touching them.
 */
interface StockLedger
{
    /**
     * Lock the inventory rows for the given products for the rest of the transaction.
     *
     * @param  array<int, string>  $productIds
     * @return Collection<string, InventoryItem>
     */
    public function lockFor(array $productIds): Collection;

    /**
     * Lock a single product's inventory row, creating the balance if it is missing.
     */
    public function lockOrCreateFor(string $productId): InventoryItem;

    /**
     * Commit available stock to an order without moving physical units.
     */
    public function reserve(InventoryItem $item, int $quantity, Order $order): void;

    /**
     * Return a reservation to available stock after a cancellation.
     */
    public function release(InventoryItem $item, int $quantity, Order $order): void;

    /**
     * Convert a reservation into a physical stock decrement on fulfilment.
     */
    public function fulfil(InventoryItem $item, int $quantity, Order $order): void;

    /**
     * Apply a manual on-hand adjustment, positive or negative.
     */
    public function adjust(InventoryItem $item, int $delta, InventoryMovementType $type): void;
}
