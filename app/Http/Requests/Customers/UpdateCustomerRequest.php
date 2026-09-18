<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates partial customer profile updates.
 */
class UpdateCustomerRequest extends FormRequest
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
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($this->route('customer'))],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Describe customer update fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => ['description' => 'Updated customer full name or business name.', 'example' => 'Acme Retail'],
            'email' => ['description' => 'Updated unique customer email address.', 'example' => 'buyer@example.com'],
            'phone' => ['description' => 'Updated customer phone number.', 'example' => '+8801812345678'],
        ];
    }
}
