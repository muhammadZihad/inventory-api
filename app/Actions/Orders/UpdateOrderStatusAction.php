<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Contracts\StockLedger;
use App\Enums\OrderStatus;
use App\Events\Orders\OrderStatusChanged;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Moves an order through the workflow and applies the stock side effects.
 *
 * The order row is re-read under a lock inside the transaction rather than
 * trusting the model the router resolved, so two concurrent transitions cannot
 * both pass the same guard.
 */
class UpdateOrderStatusAction
{
    /** Retries for transactions lost to a deadlock. */
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Bind the stock ledger.
     */
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Apply a status transition, or reject it as invalid.
     *
     * @throws InvalidOrderTransitionException when the workflow disallows the move.
     */
    public function execute(Order $order, OrderStatus $status, ?string $note): Order
    {
        [$order, $from] = DB::transaction(function () use ($order, $status, $note): array {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($status)) {
                throw new InvalidOrderTransitionException($from, $status);
            }

            if ($status === OrderStatus::Completed) {
                $this->fulfilReservations($locked);
            }

            $locked->update(['status' => $status]);

            $locked->statusHistories()->create([
                'from_status' => $from->value,
                'to_status' => $status->value,
                'note' => $note,
            ]);

            return [$locked, $from];
        }, self::TRANSACTION_ATTEMPTS);

        OrderStatusChanged::dispatch($order, $from, $status);

        return $order->fresh()->load('items');
    }

    /**
     * Turn the order's reservations into physical stock decrements.
     *
     * Fulfilment is the point at which units actually leave the warehouse, so
     * this is where on-hand finally drops.
     */
    private function fulfilReservations(Order $order): void
    {
        $items = $order->items()->get();
        $inventory = $this->ledger->lockFor($items->pluck('product_id')->all());

        foreach ($items as $item) {
            $stock = $inventory->get($item->product_id);

            if ($stock) {
                $this->ledger->fulfil($stock, $item->quantity, $order);
            }
        }
    }
}
