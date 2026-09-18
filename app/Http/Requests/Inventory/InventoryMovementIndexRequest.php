<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryMovementType;
use App\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

/**
 * Validates filtering and pagination for the inventory movement ledger.
 */
class InventoryMovementIndexRequest extends IndexRequest
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
            'type' => ['nullable', Rule::in(array_column(InventoryMovementType::cases(), 'value'))],
            'per_page' => self::paginationRules(),
        ];
    }

    /**
     * Describe movement query parameters for Scribe examples.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'type' => ['description' => 'Filter: movement type. Allowed: '.implode(', ', array_column(InventoryMovementType::cases(), 'value')).'.', 'example' => 'order_reserved'],
            ...$this->paginationQueryParameter('movements'),
        ];
    }
}
