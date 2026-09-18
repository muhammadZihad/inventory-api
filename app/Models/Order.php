<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\HasFilters;
use App\QueryFilters\OrderFilter;
use App\Support\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_id', 'order_number', 'status', 'total_amount', 'cancelled_at', 'created_by', 'updated_by', 'cancelled_by'])]
/**
 * Represents an order and its lifecycle state.
 */
class Order extends BaseModel
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasFilters, HasUlids;

    protected string $filterClass = OrderFilter::class;

    /**
     * Cast the workflow status and lifecycle date columns.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Limit a query to the orders a user is allowed to see.
     *
     * Administrators see every order; every other client sees only the orders
     * they created. Applying this at the query level means list endpoints can
     * never leak another client's orders, even if a policy check is missed.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->is_admin) {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }

    /**
     * Convert total amount between stored dollars and server-side cents.
     */
    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Money::dollarsToCents($value),
            set: fn ($value) => Money::centsToDollars($value),
        );
    }

    /**
     * Get the customer that placed this order.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get line items for this order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get inventory movements caused by this order.
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Get status transition records for this order.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }
}
