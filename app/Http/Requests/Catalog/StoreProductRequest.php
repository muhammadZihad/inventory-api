<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates data required to create a product and opening stock balance.
 */
class StoreProductRequest extends FormRequest
{
    /**
     * Allow authenticated route middleware to decide access for this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return validation rules for this request payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'ulid', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:80', 'unique:products,sku'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'status' => ['required', Rule::in(['active', 'draft', 'archived'])],
            'stock_quantity' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Describe product creation fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'category_id' => ['description' => 'Category ULID that owns the product.', 'example' => '01m2swhmj7dps93xge6qjdzjae'],
            'name' => ['description' => 'Product display name.', 'example' => 'Mechanical Keyboard'],
            'sku' => ['description' => 'Unique stock keeping unit.', 'example' => 'KEY-001'],
            'description' => ['description' => 'Product description shown in catalog responses.', 'example' => 'Compact mechanical keyboard with hot-swappable switches.'],
            'price' => ['description' => 'Product price in dollars. The API converts this to cents for calculations.', 'example' => '125.00'],
            'status' => ['description' => 'Product status. Allowed: active, draft, archived.', 'example' => 'active'],
            'stock_quantity' => ['description' => 'Opening inventory quantity.', 'example' => 50],
        ];
    }
}
