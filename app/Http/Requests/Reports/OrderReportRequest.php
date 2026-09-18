<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates order summary report filters.
 */
class OrderReportRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(OrderStatus::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * Describe report query parameters for Scribe examples.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'status' => ['description' => 'Filter: order status. Allowed: '.implode(', ', OrderStatus::values()).'.', 'example' => 'completed'],
            'from' => ['description' => 'Filter: include orders created on or after this date.', 'example' => '2026-01-01'],
            'to' => ['description' => 'Filter: include orders created on or before this date.', 'example' => '2026-12-31'],
        ];
    }
}
