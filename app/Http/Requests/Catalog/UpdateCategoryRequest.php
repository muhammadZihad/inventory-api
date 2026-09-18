<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates partial category updates.
 */
class UpdateCategoryRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($this->route('category'))],
            'status' => ['sometimes', 'required', Rule::in(['active', 'archived'])],
        ];
    }

    /**
     * Describe category update fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => ['description' => 'Updated category display name.', 'example' => 'Computer Accessories'],
            'slug' => ['description' => 'Updated unique category slug.', 'example' => 'computer-accessories'],
            'status' => ['description' => 'Category status. Allowed: active, archived.', 'example' => 'active'],
        ];
    }
}
