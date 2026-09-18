<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates registration details for a new API user.
 */
class RegisterRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ];
    }

    /**
     * Describe register request body fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Full name of the user.',
                'example' => 'Muhammad AR Zihad',
            ],
            'email' => [
                'description' => 'Unique email address used for login.',
                'example' => 'muhammad@example.com',
            ],
            'password' => [
                'description' => 'Password with at least 8 characters.',
                'example' => 'password-secret',
            ],
            'password_confirmation' => [
                'description' => 'Must match the password field.',
                'example' => 'password-secret',
            ],
        ];
    }
}
