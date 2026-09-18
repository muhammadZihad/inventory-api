<?php

declare(strict_types=1);

namespace App\Data\Auth;

/**
 * Carries validated registration data into user creation.
 */
class RegisterData
{
    /**
     * Create a registration data object from validated user input.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
    ) {}

    /**
     * Build the DTO from validated request data.
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['email'], $data['password']);
    }

    /**
     * Convert the DTO into attributes accepted by the user model.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}
