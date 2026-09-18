<?php

declare(strict_types=1);

namespace App\Data\Catalog;

use App\Support\Money;

/**
 * Normalizes product request payloads and converts money input to cents.
 */
class ProductData
{
    /**
     * Create a product data object with price already normalized to cents.
     */
    public function __construct(
        public readonly string $categoryId,
        public readonly string $name,
        public readonly string $sku,
        public readonly ?string $description,
        public readonly int $priceCents,
        public readonly string $status,
        public readonly ?int $stockQuantity = null,
    ) {}

    /**
     * Build the DTO from validated request data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            categoryId: $data['category_id'],
            name: $data['name'],
            sku: $data['sku'],
            description: $data['description'] ?? null,
            priceCents: Money::dollarsToCents($data['price']),
            status: $data['status'],
            stockQuantity: $data['stock_quantity'] ?? null,
        );
    }

    /**
     * Convert product input into model attributes with price stored as dollars.
     */
    public function toProductAttributes(): array
    {
        return [
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'price' => $this->priceCents,
            'status' => $this->status,
        ];
    }
}
