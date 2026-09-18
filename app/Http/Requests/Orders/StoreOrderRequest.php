<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates order creation payloads and line items.
 */
class StoreOrderRequest extends FormRequest
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
            'customer_id' => ['required', 'ulid', 'exists:customers,id'],
            // Capped so a single order cannot hold an unbounded number of
            // inventory row locks for the duration of its transaction.
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => [
                'required',
                'ulid',
                Rule::exists('products', 'id')->where('status', 'active'),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * Explain why an unavailable product is rejected.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.product_id.exists' => 'One or more products are unavailable for ordering.',
        ];
    }

    /**
     * Describe order creation fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'customer_id' => ['description' => 'Customer ULID placing the order.', 'example' => '01m2swhmj7dps93xge6qjdzjae'],
            'items' => ['description' => 'Order line items to reserve from stock.', 'example' => [['product_id' => '01m2swhmj7dps93xge6qjdzjaf', 'quantity' => 2]]],
            'items.*.product_id' => ['description' => 'Product ULID being ordered.', 'example' => '01m2swhmj7dps93xge6qjdzjaf'],
            'items.*.quantity' => ['description' => 'Quantity requested for this product.', 'example' => 2],
        ];
    }
}
