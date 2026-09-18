<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates order status transition requests.
 */
class UpdateOrderStatusRequest extends FormRequest
{
    /**
     * Allow the controller policy check to decide access for this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return validation rules for this request payload.
     *
     * Cancellation is excluded here on purpose: it releases reserved stock and
     * is only reachable through the dedicated cancel endpoint, so this endpoint
     * can never be used to cancel an order without restoring its inventory.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(OrderStatus::clientTransitionableValues())],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Return the validated target status as an enum.
     */
    public function status(): OrderStatus
    {
        return OrderStatus::from($this->validated('status'));
    }

    /**
     * Explain why cancellation is rejected by this endpoint.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Allowed statuses are: '.implode(', ', OrderStatus::clientTransitionableValues()).'. Use POST /orders/{order}/cancel to cancel an order so its reserved stock is released.',
        ];
    }

    /**
     * Describe order status update fields for generated Scribe documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'status' => ['description' => 'Next order status. Allowed: '.implode(', ', OrderStatus::clientTransitionableValues()).'.', 'example' => 'confirmed'],
            'note' => ['description' => 'Optional note explaining the status transition.', 'example' => 'Stock confirmed and order is ready for processing.'],
        ];
    }
}
