<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * The single writer for stock balances and the movement ledger.
 *
 * Every balance change goes through this class so that the two invariants of
 * the inventory model are enforced in one place:
 *
 *   1. on-hand and reserved only ever change under a held row lock, and
 *   2. every change writes exactly one ledger row, so the balances can be
 *      reconciled by replaying the ledger.
 *
 * Callers are responsible for opening the transaction; this class refuses to
 * guess transaction boundaries it cannot see.
 */
class InventoryLedger
{
    /**
     * Lock the inventory rows for the given products for the rest of the transaction.
     *
     * Rows are locked in a single statement ordered by product id. Taking the
     * locks in a deterministic order means two concurrent orders covering the
     * same products queue behind each other instead of deadlocking.
     *
     * @param  array<int, string>  $productIds
     * @return Collection<string, InventoryItem>
     */
    public function lockFor(array $productIds): Collection
    {
        sort($productIds);

        /** @var Collection<string, InventoryItem> $items */
        $items = InventoryItem::query()
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        return $items;
    }

    /**
     * Lock a single product's inventory row, creating the balance if it is missing.
     *
     * The row is created outside the lock (a lock cannot be taken on a row that
     * does not exist) and then re-read with the lock held, so the returned
     * model always reflects a locked, current row.
     */
    public function lockOrCreateFor(string $productId): InventoryItem
    {
        try {
            InventoryItem::query()->firstOrCreate(
                ['product_id' => $productId],
                ['quantity_on_hand' => 0, 'quantity_reserved' => 0],
            );
        } catch (QueryException) {
            // A concurrent request created the row first, which is the outcome
            // we wanted anyway. The locking read below picks it up.
        }

        return InventoryItem::query()
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Commit available stock to an order without moving physical units.
     */
    public function reserve(InventoryItem $item, int $quantity, Order $order): void
    {
        $item->increment('quantity_reserved', $quantity);

        $this->record($item, InventoryMovementType::OrderReserved, 0, $quantity, $order);
    }

    /**
     * Return a reservation to available stock after a cancellation.
     */
    public function release(InventoryItem $item, int $quantity, Order $order): void
    {
        // Clamp so a partially reconciled balance can never underflow the
        // unsigned column; the ledger records what was actually released.
        $released = min($quantity, $item->quantity_reserved);

        if ($released === 0) {
            return;
        }

        $item->decrement('quantity_reserved', $released);

        $this->record($item, InventoryMovementType::ReservationReleased, 0, -$released, $order);
    }

    /**
     * Convert a reservation into a physical stock decrement on fulfilment.
     */
    public function fulfil(InventoryItem $item, int $quantity, Order $order): void
    {
        $shipped = min($quantity, $item->quantity_on_hand);
        $released = min($quantity, $item->quantity_reserved);

        $item->decrement('quantity_on_hand', $shipped);
        $item->decrement('quantity_reserved', $released);

        $this->record($item, InventoryMovementType::OrderFulfilled, -$shipped, -$released, $order);
    }

    /**
     * Apply a manual on-hand adjustment, positive or negative.
     */
    public function adjust(InventoryItem $item, int $delta, InventoryMovementType $type): void
    {
        if ($delta >= 0) {
            $item->increment('quantity_on_hand', $delta);
        } else {
            $item->decrement('quantity_on_hand', abs($delta));
        }

        $this->record($item, $type, $delta, 0);
    }

    /**
     * Append one ledger row describing a balance change.
     *
     * Eloquent's increment/decrement update the in-memory attributes as well as
     * the row, and the row is locked, so the post-change balances can be read
     * from the model without an extra query.
     */
    private function record(
        InventoryItem $item,
        InventoryMovementType $type,
        int $quantityDelta,
        int $reservedDelta,
        ?Order $order = null,
    ): void {
        InventoryMovement::query()->create([
            'product_id' => $item->product_id,
            'order_id' => $order?->id,
            'type' => $type,
            'quantity_delta' => $quantityDelta,
            'quantity_after' => $item->quantity_on_hand,
            'reserved_delta' => $reservedDelta,
            'reserved_after' => $item->quantity_reserved,
        ]);
    }
}
