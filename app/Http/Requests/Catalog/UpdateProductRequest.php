<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates partial product updates.
 */
class UpdateProductRequest extends FormRequest
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
            'category_id' => ['sometimes', 'required', 'ulid', 'exists:categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => ['sometimes', 'required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($this->route('product'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'draft', 'archived'])],
        ];
    }

    /**
     * Describe product update fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'category_id' => ['description' => 'Updated category ULID.', 'example' => '01m2swhmj7dps93xge6qjdzjae'],
            'name' => ['description' => 'Updated product display name.', 'example' => 'Wireless Mechanical Keyboard'],
            'sku' => ['description' => 'Updated unique stock keeping unit.', 'example' => 'KEY-002'],
            'description' => ['description' => 'Updated product description.', 'example' => 'Wireless compact keyboard with RGB backlight.'],
            'price' => ['description' => 'Updated product price in dollars.', 'example' => '149.00'],
            'status' => ['description' => 'Product status. Allowed: active, draft, archived.', 'example' => 'active'],
        ];
    }
}
