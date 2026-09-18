<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'product_id', 'quantity', 'unit_price', 'line_total'])]
/**
 * Represents a single product line within an order.
 */
class OrderItem extends BaseModel
{
    use HasUlids;

    /**
     * Convert unit price between stored dollars and server-side cents.
     */
    protected function unitPrice(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Money::dollarsToCents($value),
            set: fn ($value) => Money::centsToDollars($value),
        );
    }

    /**
     * Convert line total between stored dollars and server-side cents.
     */
    protected function lineTotal(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Money::dollarsToCents($value),
            set: fn ($value) => Money::centsToDollars($value),
        );
    }

    /**
     * Get the order that owns this line item.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product sold on this line item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
