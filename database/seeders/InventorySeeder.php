<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes one inventory_items row per product from the balances the seeded
 * orders produced.
 *
 * This runs last: the balances are only known once every order has been
 * applied, and writing them directly keeps inventory_items in agreement with
 * both the orders and the movement ledger.
 */
final class InventorySeeder
{
    /**
     * @param  OutputStyle|null  $output  Console output for progress bars, if any.
     * @param  string  $userId  Demo user recorded as the creator of every row.
     * @param  int  $chunkSize  Rows per bulk insert.
     * @param  int  $now  Unix timestamp the balances are current as of.
     */
    public function __construct(
        private readonly ?OutputStyle $output,
        private readonly string $userId,
        private readonly int $chunkSize,
        private readonly int $now,
    ) {}

    /**
     * Persist the final stock balance for every product.
     */
    public function seed(SeedCatalog $catalog, SeedBalances $balances): int
    {
        $inserter = new ChunkedInserter('inventory_items', $this->chunkSize);
        $count = count($catalog->productIds);

        $bar = $this->output?->createProgressBar($count);
        $bar?->setFormat(' seeding inventory items  %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');

        $updatedAt = date('Y-m-d H:i:s', $this->now);

        for ($i = 0; $i < $count; $i++) {
            $onHand = $balances->onHand[$i];
            $reserved = $balances->reserved[$i];

            // Both columns are unsignedInteger, so a negative balance would be
            // silently rejected or wrapped. Fail loudly instead.
            if ($onHand < 0 || $reserved < 0) {
                throw new RuntimeException(sprintf(
                    'Negative stock for product index %d: on hand %d, reserved %d.',
                    $i,
                    $onHand,
                    $reserved,
                ));
            }

            $createdAt = date('Y-m-d H:i:s', $catalog->productCreatedAt[$i]);

            $inserter->add([
                'id' => (string) Str::ulid(),
                'product_id' => $catalog->productIds[$i],
                'quantity_on_hand' => $onHand,
                'quantity_reserved' => $reserved,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
                'created_by' => $this->userId,
                'updated_by' => $this->userId,
            ]);

            if ($inserter->isFull()) {
                $inserter->flush();
            }

            if ($i % 5_000 === 0) {
                $bar?->setProgress($i);
            }
        }

        $inserter->flush();
        $bar?->finish();
        $this->output?->newLine();

        return $inserter->written();
    }
}
