<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Contracts\StockLedger;
use App\Data\Orders\CreateOrderData;
use App\Data\Orders\OrderItemData;
use App\Enums\OrderStatus;
use App\Events\Orders\OrderCreated;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an order and reserves its stock atomically.
 *
 * Overselling is prevented by taking a row lock on every inventory row the
 * order touches *before* availability is checked, and holding those locks for
 * the whole transaction. A concurrent order for the same product blocks on the
 * lock and re-reads the balance after the first order commits, so it sees the
 * reservation the first order made.
 */
class CreateOrderAction
{
    /** Retries for transactions lost to a deadlock. */
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Bind the stock ledger.
     */
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Reserve stock and persist the order, or fail without side effects.
     *
     * @throws InsufficientStockException when any line exceeds available stock.
     */
    public function execute(CreateOrderData $data): Order
    {
        $order = DB::transaction(function () use ($data): Order {
            $items = $this->consolidate($data);
            $productIds = $items->map(fn (OrderItemData $item): string => $item->productId)->all();

            $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
            $inventory = $this->ledger->lockFor($productIds);

            $this->assertStockIsAvailable($items, $inventory);

            $lines = $items->map(function (OrderItemData $item) use ($products): array {
                $product = $products->get($item->productId);

                return [
                    'product_id' => $product->id,
                    'quantity' => $item->quantity,
                    'unit_price' => $product->price,
                    'line_total' => $product->price * $item->quantity,
                ];
            });

            // The total is known before the insert, so the order is written once.
            $order = Order::query()->create([
                'customer_id' => $data->customerId,
                'order_number' => $this->generateOrderNumber(),
                'status' => OrderStatus::Pending,
                'total_amount' => $lines->sum('line_total'),
            ]);

            $order->items()->createMany($lines->all());

            foreach ($items as $item) {
                $this->ledger->reserve($inventory->get($item->productId), $item->quantity, $order);
            }

            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => OrderStatus::Pending->value,
                'note' => 'Order created.',
            ]);

            return $order;
        }, self::TRANSACTION_ATTEMPTS);

        // Dispatched outside the transaction so listeners never observe, or
        // act on, state that a rollback would undo.
        OrderCreated::dispatch($order);

        return $order;
    }

    /**
     * Merge duplicate lines so a payload listing a product twice is checked once.
     *
     * @return Collection<int, OrderItemData>
     */
    private function consolidate(CreateOrderData $data): Collection
    {
        return $data->items
            ->groupBy(fn (OrderItemData $item): string => $item->productId)
            ->map(fn ($rows, string $productId): OrderItemData => new OrderItemData($productId, (int) $rows->sum('quantity')))
            ->values();
    }

    /**
     * Reject the order if any line exceeds the available balance.
     *
     * Availability is on-hand minus what other unshipped orders already hold,
     * so two orders can never reserve the same physical unit.
     *
     * @param  Collection<int, OrderItemData>  $items
     * @param  EloquentCollection<string, InventoryItem>  $inventory
     */
    private function assertStockIsAvailable(Collection $items, EloquentCollection $inventory): void
    {
        $shortfalls = [];

        foreach ($items as $item) {
            $stock = $inventory->get($item->productId);
            $available = $stock?->available_quantity ?? 0;

            if ($available < $item->quantity) {
                $shortfalls[] = [
                    'product_id' => $item->productId,
                    'requested' => $item->quantity,
                    'available' => $available,
                ];
            }
        }

        if ($shortfalls !== []) {
            throw new InsufficientStockException($shortfalls);
        }
    }

    /**
     * Build a human-readable, collision-resistant order number.
     */
    private function generateOrderNumber(): string
    {
        return 'ORD-'.Str::upper(Str::random(12));
    }
}
