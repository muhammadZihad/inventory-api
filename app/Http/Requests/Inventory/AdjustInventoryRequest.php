<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryMovementType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates manual inventory adjustment requests.
 */
class AdjustInventoryRequest extends FormRequest
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
            'type' => ['required', Rule::in(InventoryMovementType::manualValues())],
            'quantity' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
        ];
    }

    /**
     * Enforce the sign rules that depend on the adjustment type.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');
            $quantity = $this->input('quantity');

            if ($type === InventoryMovementType::Restock->value && is_numeric($quantity) && (int) $quantity < 0) {
                $validator->errors()->add('quantity', 'A restock must add stock, so the quantity must be positive. Use a correction to remove stock.');
            }
        });
    }

    /**
     * Describe inventory adjustment fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'type' => ['description' => 'Adjustment type. Allowed: restock, correction.', 'example' => 'restock'],
            'quantity' => ['description' => 'Signed quantity applied to on-hand stock. Restocks must be positive; corrections may be negative to record shrinkage or a recount.', 'example' => 25],
        ];
    }
}
