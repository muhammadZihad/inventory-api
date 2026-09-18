<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates data required to create a customer profile.
 */
class StoreCustomerRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Describe customer creation fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => ['description' => 'Customer full name or business name.', 'example' => 'Demo Customer'],
            'email' => ['description' => 'Unique customer email address.', 'example' => 'customer@example.com'],
            'phone' => ['description' => 'Optional customer phone number.', 'example' => '+8801712345678'],
        ];
    }
}
