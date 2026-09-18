<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasFilters;
use App\QueryFilters\InventoryItemFilter;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'quantity_on_hand', 'quantity_reserved', 'updated_by'])]
/**
 * Tracks on-hand and reserved stock for a product.
 *
 * on-hand    physical units held in the warehouse
 * reserved   units committed to orders that have not shipped yet
 * available  on-hand minus reserved, the only figure an order may draw from
 */
class InventoryItem extends BaseModel
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory, HasFilters, HasUlids;

    /** SQL expression for available stock, shared by filters and sorting. */
    public const AVAILABLE_EXPRESSION = '(quantity_on_hand - quantity_reserved)';

    protected string $filterClass = InventoryItemFilter::class;

    /**
     * Cast stock balances to integers.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'integer',
            'quantity_reserved' => 'integer',
        ];
    }

    /**
     * Expose the quantity an incoming order is allowed to reserve.
     */
    protected function availableQuantity(): Attribute
    {
        return Attribute::get(
            fn (): int => max(0, $this->quantity_on_hand - $this->quantity_reserved),
        );
    }

    /**
     * Get the product this inventory balance belongs to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
