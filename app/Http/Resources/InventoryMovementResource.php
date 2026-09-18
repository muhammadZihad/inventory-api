<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms inventory ledger entries into the public API shape.
 */
class InventoryMovementResource extends JsonResource
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
            'order_id' => $this->order_id,
            'type' => $this->type->value,
            'quantity_delta' => $this->quantity_delta,
            'quantity_after' => $this->quantity_after,
            'reserved_delta' => $this->reserved_delta,
            'reserved_after' => $this->reserved_after,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
