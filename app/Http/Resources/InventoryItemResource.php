<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms inventory balances into the public API shape.
 */
class InventoryItemResource extends JsonResource
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
            'product_id' => $this->product_id,
            'product_title' => $this->whenLoaded('product', fn () => $this->product->name),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'title' => $this->product->name,
            ]),
            'quantity_on_hand' => $this->quantity_on_hand,
            'quantity_reserved' => $this->quantity_reserved,
            // What a new order may actually draw from: physical stock minus
            // the units already committed to unshipped orders.
            'available_quantity' => $this->available_quantity,
        ];
    }
}
