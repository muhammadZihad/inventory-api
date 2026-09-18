<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Events\Orders\OrderCancelled;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Services\InventoryLedger;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an order and releases the stock it was holding.
 *
 * The order row is locked and its status re-read inside the transaction, so
 * two concurrent cancellations cannot both release the same reservation and
 * inflate the balance.
 */
class CancelOrderAction
{
    /** Retries for transactions lost to a deadlock. */
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Bind the stock ledger.
     */
    public function __construct(private readonly InventoryLedger $ledger) {}

    /**
     * Cancel the order, releasing its reservations exactly once.
     *
     * Cancelling an already-cancelled order is a no-op rather than an error, so
     * a retried cancel request stays safe.
     *
     * @throws InvalidOrderTransitionException when the order is already completed.
     */
    public function execute(Order $order, string $actorId): Order
    {
        [$order, $cancelled] = DB::transaction(function () use ($order, $actorId): array {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Cancelled) {
                return [$locked, false];
            }

            if (! $locked->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw new InvalidOrderTransitionException($locked->status, OrderStatus::Cancelled);
            }

            $from = $locked->status;
            $this->releaseReservations($locked);

            $locked->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
            ]);

            $locked->statusHistories()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::Cancelled->value,
                'note' => 'Order cancelled.',
            ]);

            return [$locked, true];
        }, self::TRANSACTION_ATTEMPTS);

        if ($cancelled) {
            OrderCancelled::dispatch($order);
        }

        return $order->fresh()->load('items');
    }

    /**
     * Return every reserved unit on the order to available stock.
     */
    private function releaseReservations(Order $order): void
    {
        $items = $order->items()->get();
        $inventory = $this->ledger->lockFor($items->pluck('product_id')->all());

        foreach ($items as $item) {
            $stock = $inventory->get($item->product_id);

            if ($stock) {
                $this->ledger->release($stock, $item->quantity, $order);
            }
        }
    }
}
