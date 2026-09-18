<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'order_id', 'type', 'quantity_delta', 'quantity_after', 'reserved_delta', 'reserved_after', 'created_by'])]
/**
 * Append-only ledger of every on-hand and reserved stock change.
 */
class InventoryMovement extends BaseModel
{
    use HasUlids;

    /**
     * Cast the movement type and its signed quantities.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity_delta' => 'integer',
            'quantity_after' => 'integer',
            'reserved_delta' => 'integer',
            'reserved_after' => 'integer',
        ];
    }

    /**
     * Get the product whose balance this movement changed.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the order that caused this movement, when it was order-driven.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
