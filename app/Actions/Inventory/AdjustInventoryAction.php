<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Contracts\StockLedger;
use App\Data\Inventory\InventoryAdjustmentData;
use App\Events\Inventory\InventoryChanged;
use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies a manual stock adjustment and records it in the movement ledger.
 *
 * Adjustments are signed: a restock adds units, a correction may add or remove
 * them for recounts, damage, or shrinkage.
 */
class AdjustInventoryAction
{
    /** Retries for transactions lost to a deadlock. */
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Bind the stock ledger.
     */
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Adjust on-hand stock for a product under a row lock.
     *
     * @throws ValidationException when the adjustment would leave reserved stock uncovered.
     */
    public function execute(Product $product, InventoryAdjustmentData $data): InventoryItem
    {
        $inventory = DB::transaction(function () use ($product, $data): InventoryItem {
            $inventory = $this->ledger->lockOrCreateFor($product->id);
            $resulting = $inventory->quantity_on_hand + $data->quantity;

            // On-hand may never fall below what orders already hold, or the
            // reserved units would no longer be backed by physical stock.
            if ($resulting < $inventory->quantity_reserved) {
                throw ValidationException::withMessages([
                    'quantity' => [sprintf(
                        'This adjustment would leave %d units on hand while %d are reserved for open orders.',
                        max($resulting, 0),
                        $inventory->quantity_reserved,
                    )],
                ]);
            }

            $this->ledger->adjust($inventory, $data->quantity, $data->type);

            return $inventory;
        }, self::TRANSACTION_ATTEMPTS);

        InventoryChanged::dispatch([$product->id]);

        return $inventory->fresh();
    }
}
