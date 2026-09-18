<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms category models into the public API shape.
 */
class CategoryResource extends JsonResource
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
            'slug' => $this->slug,
            'status' => $this->status,
            // whenCounted omits the key unless the caller eager-counted it,
            // rather than silently issuing a COUNT per row.
            'products_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
