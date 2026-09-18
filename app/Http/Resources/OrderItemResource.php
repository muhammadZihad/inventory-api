<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms order line items and exposes money values as dollars.
 */
class OrderItemResource extends JsonResource
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
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
            ]),
            'quantity' => $this->quantity,
            'unit_price' => Money::centsToDollars($this->unit_price),
            'line_total' => Money::centsToDollars($this->line_total),
        ];
    }
}
