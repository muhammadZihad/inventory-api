<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Support\Money;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds orders together with everything the order workflow would have written:
 * line items, status history, and the inventory movement ledger.
 *
 * Orders are generated in ascending creation order while per-product stock
 * balances are kept in memory, so the movements carry a truthful running
 * balance and the final balances written to inventory_items are exactly the
 * result of applying these orders to the opening stock.
 */
final class OrderSeeder
{
    /** Status mix, as cumulative percentage thresholds. */
    private const STATUS_PENDING_UPTO = 15;

    private const STATUS_CONFIRMED_UPTO = 35;

    private const STATUS_COMPLETED_UPTO = 90;

    /** Share of lines drawn from the small set of fast moving products. */
    private const HOT_PRODUCT_LINE_SHARE = 25;

    private const HOT_PRODUCT_CATALOG_SHARE = 0.025;

    /** Bounds for how long an order takes to move through its lifecycle. */
    private const CONFIRM_LAG_MIN = 1_800;

    private const CONFIRM_LAG_MAX = 259_200;

    private const COMPLETE_LAG_MIN = 3_600;

    private const COMPLETE_LAG_MAX = 604_800;

    private const CANCEL_LAG_MIN = 1_800;

    private const CANCEL_LAG_MAX = 432_000;

    /**
     * @param  OutputStyle|null  $output  Console output for progress bars, if any.
     * @param  string  $userId  Demo user recorded as the creator of every order.
     * @param  int  $chunkSize  Rows per bulk insert.
     * @param  int  $windowStart  Unix timestamp the first order is placed at.
     * @param  int  $now  Unix timestamp the seeded history ends at.
     */
    public function __construct(
        private readonly ?OutputStyle $output,
        private readonly string $userId,
        private readonly int $chunkSize,
        private readonly int $windowStart,
        private readonly int $now,
    ) {}

    /**
     * Seed orders and return the stock balances they leave behind.
     */
    public function seed(SeedCatalog $catalog, int $orderCount, int $maxLines): SeedBalances
    {
        $orders = new ChunkedInserter('orders', $this->chunkSize);
        $items = new ChunkedInserter('order_items', $this->chunkSize);
        $histories = new ChunkedInserter('order_status_histories', $this->chunkSize);
        $movements = new ChunkedInserter('inventory_movements', $this->chunkSize);

        $productCount = count($catalog->productIds);
        $customerCount = count($catalog->customerIds);

        // Running balances, keyed by product index. Plain integer arrays cost
        // about 16 bytes per product, so the whole catalog fits comfortably.
        $onHand = $catalog->openingStock;
        $reserved = array_fill(0, $productCount, 0);

        // Timestamp of the last ledger entry per product. Movements are bumped
        // past it so that replaying a product's ledger in created_at order
        // follows the same sequence this loop applied.
        $ledgerAt = $catalog->productCreatedAt;

        $hotLimit = max(1, (int) ($productCount * self::HOT_PRODUCT_CATALOG_SHARE));
        $step = ($this->now - $this->windowStart) / max(1, $orderCount);

        $productCursor = 0;
        $customerCursor = 0;

        $bar = $this->output?->createProgressBar($orderCount);
        $bar?->setFormat(' seeding orders           %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');

        for ($i = 0; $i < $orderCount; $i++) {
            $orderAt = $this->windowStart + (int) ($step * $i);

            // Only reference catalog rows that already existed at this point.
            while ($productCursor + 1 < $productCount && $catalog->productCreatedAt[$productCursor + 1] <= $orderAt) {
                $productCursor++;
            }

            while ($customerCursor + 1 < $customerCount && $catalog->customerCreatedAt[$customerCursor + 1] <= $orderAt) {
                $customerCursor++;
            }

            $orderId = (string) Str::ulid();
            $status = $this->rollStatus();
            $orderAtFormatted = date('Y-m-d H:i:s', $orderAt);

            $confirmedAt = min($this->now, $orderAt + mt_rand(self::CONFIRM_LAG_MIN, self::CONFIRM_LAG_MAX));
            $completedAt = min($this->now, $confirmedAt + mt_rand(self::COMPLETE_LAG_MIN, self::COMPLETE_LAG_MAX));
            $cancelledAt = min($this->now, $orderAt + mt_rand(self::CANCEL_LAG_MIN, self::CANCEL_LAG_MAX));

            $lineCount = $this->rollLineCount($maxLines);
            $chosen = [];
            $totalCents = 0;

            for ($line = 0; $line < $lineCount; $line++) {
                $productIndex = $this->pickProduct($productCursor, $hotLimit);

                if (in_array($productIndex, $chosen, true)) {
                    continue;
                }

                $chosen[] = $productIndex;

                $quantity = $this->rollQuantity();
                $unitPriceCents = $catalog->productPriceCents[$productIndex];
                $lineTotalCents = $unitPriceCents * $quantity;
                $totalCents += $lineTotalCents;

                $items->add([
                    'id' => (string) Str::ulid(),
                    'order_id' => $orderId,
                    'product_id' => $catalog->productIds[$productIndex],
                    'quantity' => $quantity,
                    'unit_price' => Money::centsToDollars($unitPriceCents),
                    'line_total' => Money::centsToDollars($lineTotalCents),
                    'created_at' => $orderAtFormatted,
                    'updated_at' => $orderAtFormatted,
                    'created_by' => $this->userId,
                    'updated_by' => null,
                ]);

                // Placing the order always reserves stock.
                $reserved[$productIndex] += $quantity;
                $reserveAt = max($orderAt, $ledgerAt[$productIndex] + 1);
                $ledgerAt[$productIndex] = $reserveAt;

                $movements->add($this->movement(
                    $catalog->productIds[$productIndex],
                    $orderId,
                    InventoryMovementType::OrderReserved,
                    0,
                    $onHand[$productIndex],
                    $quantity,
                    $reserved[$productIndex],
                    $reserveAt,
                ));

                if ($status === OrderStatus::Completed) {
                    if ($onHand[$productIndex] < $quantity) {
                        throw new RuntimeException(sprintf(
                            'Opening stock too low for product index %d: %d on hand, %d required.',
                            $productIndex,
                            $onHand[$productIndex],
                            $quantity,
                        ));
                    }

                    // Fulfilment ships the units and releases the reservation.
                    $onHand[$productIndex] -= $quantity;
                    $reserved[$productIndex] -= $quantity;
                    $fulfilledAt = max($completedAt, $ledgerAt[$productIndex] + 1);
                    $ledgerAt[$productIndex] = $fulfilledAt;

                    $movements->add($this->movement(
                        $catalog->productIds[$productIndex],
                        $orderId,
                        InventoryMovementType::OrderFulfilled,
                        -$quantity,
                        $onHand[$productIndex],
                        -$quantity,
                        $reserved[$productIndex],
                        $fulfilledAt,
                    ));
                } elseif ($status === OrderStatus::Cancelled) {
                    // Cancelling gives the reservation back, leaving no net effect.
                    $reserved[$productIndex] -= $quantity;
                    $releasedAt = max($cancelledAt, $ledgerAt[$productIndex] + 1);
                    $ledgerAt[$productIndex] = $releasedAt;

                    $movements->add($this->movement(
                        $catalog->productIds[$productIndex],
                        $orderId,
                        InventoryMovementType::ReservationReleased,
                        0,
                        $onHand[$productIndex],
                        -$quantity,
                        $reserved[$productIndex],
                        $releasedAt,
                    ));
                }
            }

            $touchedAt = match ($status) {
                OrderStatus::Pending => $orderAt,
                OrderStatus::Confirmed => $confirmedAt,
                OrderStatus::Completed => $completedAt,
                OrderStatus::Cancelled => $cancelledAt,
            };

            $orders->add([
                'id' => $orderId,
                'customer_id' => $catalog->customerIds[mt_rand(0, $customerCursor)],
                'order_number' => 'ORD-'.str_pad((string) ($i + 1), 8, '0', STR_PAD_LEFT),
                'status' => $status->value,
                'total_amount' => Money::centsToDollars($totalCents),
                'cancelled_at' => $status === OrderStatus::Cancelled ? date('Y-m-d H:i:s', $cancelledAt) : null,
                'cancelled_by' => $status === OrderStatus::Cancelled ? $this->userId : null,
                'created_at' => $orderAtFormatted,
                'updated_at' => date('Y-m-d H:i:s', $touchedAt),
                'created_by' => $this->userId,
                'updated_by' => $status === OrderStatus::Pending ? null : $this->userId,
            ]);

            foreach ($this->transitions($status, $orderAt, $confirmedAt, $completedAt, $cancelledAt) as $transition) {
                [$from, $to, $at, $note] = $transition;

                $histories->add([
                    'id' => (string) Str::ulid(),
                    'order_id' => $orderId,
                    'from_status' => $from,
                    'to_status' => $to,
                    'note' => $note,
                    'created_at' => date('Y-m-d H:i:s', $at),
                    'updated_at' => date('Y-m-d H:i:s', $at),
                    'created_by' => $this->userId,
                    'updated_by' => null,
                ]);
            }

            // Children are only ever buffered alongside their parent order, so
            // flushing all four buffers together at an order boundary always
            // writes the order before the rows that reference it.
            if ($orders->isFull() || $items->isFull() || $histories->isFull() || $movements->isFull()) {
                $orders->flush();
                $items->flush();
                $histories->flush();
                $movements->flush();
            }

            if ($i % 5_000 === 0) {
                $bar?->setProgress($i);
            }
        }

        $orders->flush();
        $items->flush();
        $histories->flush();
        $movements->flush();
        $bar?->finish();
        $this->output?->newLine();

        $this->report('orders', $orders->written());
        $this->report('order items', $items->written());
        $this->report('status histories', $histories->written());
        $this->report('order movements', $movements->written());

        return new SeedBalances(onHand: $onHand, reserved: $reserved);
    }

    /**
     * Build one inventory movement row.
     *
     * @return array<string, mixed>
     */
    private function movement(
        string $productId,
        string $orderId,
        InventoryMovementType $type,
        int $quantityDelta,
        int $quantityAfter,
        int $reservedDelta,
        int $reservedAfter,
        int $at,
    ): array {
        $formatted = date('Y-m-d H:i:s', $at);

        return [
            'id' => (string) Str::ulid(),
            'product_id' => $productId,
            'order_id' => $orderId,
            'type' => $type->value,
            'quantity_delta' => $quantityDelta,
            'quantity_after' => $quantityAfter,
            'reserved_delta' => $reservedDelta,
            'reserved_after' => $reservedAfter,
            'created_at' => $formatted,
            'updated_at' => $formatted,
            'created_by' => $this->userId,
            'updated_by' => null,
        ];
    }

    /**
     * Build the status history a given final status must have gone through.
     *
     * @return list<array{0: string|null, 1: string, 2: int, 3: string}>
     */
    private function transitions(OrderStatus $status, int $orderAt, int $confirmedAt, int $completedAt, int $cancelledAt): array
    {
        $rows = [[null, OrderStatus::Pending->value, $orderAt, 'Order placed.']];

        if ($status === OrderStatus::Confirmed || $status === OrderStatus::Completed) {
            $rows[] = [OrderStatus::Pending->value, OrderStatus::Confirmed->value, $confirmedAt, 'Payment confirmed, stock reserved.'];
        }

        if ($status === OrderStatus::Completed) {
            $rows[] = [OrderStatus::Confirmed->value, OrderStatus::Completed->value, $completedAt, 'Shipped, reserved stock released from inventory.'];
        }

        if ($status === OrderStatus::Cancelled) {
            $rows[] = [OrderStatus::Pending->value, OrderStatus::Cancelled->value, $cancelledAt, 'Cancelled, reserved stock returned.'];
        }

        return $rows;
    }

    /**
     * Draw an order status from the configured mix.
     */
    private function rollStatus(): OrderStatus
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= self::STATUS_PENDING_UPTO => OrderStatus::Pending,
            $roll <= self::STATUS_CONFIRMED_UPTO => OrderStatus::Confirmed,
            $roll <= self::STATUS_COMPLETED_UPTO => OrderStatus::Completed,
            default => OrderStatus::Cancelled,
        };
    }

    /**
     * Draw how many lines an order has, weighted towards small baskets.
     */
    private function rollLineCount(int $maxLines): int
    {
        $roll = mt_rand(1, 100);

        $lines = match (true) {
            $roll <= 35 => 1,
            $roll <= 65 => 2,
            $roll <= 85 => 3,
            default => 4,
        };

        return min($lines, $maxLines);
    }

    /**
     * Draw a line quantity, weighted towards single units.
     */
    private function rollQuantity(): int
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 45 => 1,
            $roll <= 75 => 2,
            $roll <= 90 => 3,
            $roll <= 97 => 4,
            default => 5,
        };
    }

    /**
     * Pick a product index, favouring a small set of fast moving products.
     */
    private function pickProduct(int $cursor, int $hotLimit): int
    {
        if (mt_rand(1, 100) <= self::HOT_PRODUCT_LINE_SHARE) {
            return mt_rand(0, min($cursor, $hotLimit - 1));
        }

        return mt_rand(0, $cursor);
    }

    /**
     * Print how many rows a phase wrote.
     */
    private function report(string $label, int $rows): void
    {
        $this->output?->writeln(sprintf('  <info>%s</info>: %s rows', $label, number_format($rows)));
    }
}
