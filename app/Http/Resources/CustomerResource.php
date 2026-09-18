<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms customer models into the public API shape.
 */
class CustomerResource extends JsonResource
{
    /**
     * Convert the resource into an array response payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'orders_count' => (int) ($this->orders_count ?? 0),
            'completed_orders_count' => (int) ($this->completed_orders_count ?? 0),
            'total_order_amount' => Money::centsToDollars(Money::dollarsToCents($this->total_order_amount ?? 0)),
            'customer_value_rank' => $this->customer_value_rank ? (int) $this->customer_value_rank : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
