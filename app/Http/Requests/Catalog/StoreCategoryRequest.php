<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates data required to create a product category.
 */
class StoreCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('categories', 'slug')],
            'status' => ['required', Rule::in(['active', 'archived'])],
        ];
    }

    /**
     * Describe category creation fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => ['description' => 'Display name of the category.', 'example' => 'Electronics'],
            'slug' => ['description' => 'Optional URL-safe unique category slug.', 'example' => 'electronics'],
            'status' => ['description' => 'Category status. Allowed: active, archived.', 'example' => 'active'],
        ];
    }
}
