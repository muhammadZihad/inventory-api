<?php

declare(strict_types=1);

namespace App\Data\Customers;

use App\Models\Customer;

/**
 * Normalizes customer request payloads for persistence.
 */
class CustomerData
{
    /**
     * Create a customer data object from validated profile input.
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
    ) {}

    /**
     * Build the DTO from validated request data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
        );
    }

    /**
     * Convert customer input into attributes for creating a customer.
     */
    public function toCreateAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }

    /**
     * Convert only supplied customer fields into update attributes.
     */
    public function toUpdateAttributes(Customer $customer): array
    {
        return array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ], fn ($value) => $value !== null);
    }
}
