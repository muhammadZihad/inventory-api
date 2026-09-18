<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms orders and nested line items into the public API shape.
 */
class OrderResource extends JsonResource
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
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer->name),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
            ]),
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'total_amount' => Money::centsToDollars($this->total_amount),
            'items_count' => (int) ($this->items_count ?? $this->whenLoaded('items', fn () => $this->items->count(), 0)),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
