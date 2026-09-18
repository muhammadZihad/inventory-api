<?php

declare(strict_types=1);

namespace App\Data\Catalog;

use App\Models\Category;
use Illuminate\Support\Str;

/**
 * Normalizes category request payloads for create and update operations.
 */
class CategoryData
{
    /**
     * Create a category data object from validated catalog input.
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $slug,
        public readonly ?string $status,
    ) {}

    /**
     * Build the DTO from validated request data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            slug: $data['slug'] ?? null,
            status: $data['status'] ?? null,
        );
    }

    /**
     * Convert category input into attributes for creating a category.
     */
    public function toCreateAttributes(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug ?? Str::slug($this->name).'-'.Str::lower(Str::random(6)),
            'status' => $this->status,
        ];
    }

    /**
     * Convert only supplied category fields into update attributes.
     */
    public function toUpdateAttributes(Category $category): array
    {
        return array_filter([
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
        ], fn ($value) => $value !== null);
    }
}
