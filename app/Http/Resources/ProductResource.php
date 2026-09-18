<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms products with category, inventory, and dollar price output.
 */
class ProductResource extends JsonResource
{
    /**
     * Convert the resource into an array response payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Resolved once, and only when the caller eager-loaded it, so this
        // resource can never trigger a query per row.
        $inventory = $this->relationLoaded('inventory') ? $this->inventory : null;

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category->name),
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'price' => Money::centsToDollars($this->price),
            'status' => $this->status,
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'inventory' => InventoryItemResource::make($this->whenLoaded('inventory')),
            'stock' => (int) ($inventory?->quantity_on_hand ?? 0),
            'available_stock' => (int) ($inventory?->available_quantity ?? 0),
            'units_sold' => (int) ($this->units_sold ?? 0),
            'orders_count' => (int) ($this->orders_count ?? 0),
            'gross_sales' => Money::centsToDollars(Money::dollarsToCents($this->gross_sales ?? 0)),
            'sales_rank' => $this->sales_rank ? (int) $this->sales_rank : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
