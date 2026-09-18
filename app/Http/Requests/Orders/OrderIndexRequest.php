<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use App\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

/**
 * Validates order list filters and pagination.
 */
class OrderIndexRequest extends IndexRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(OrderStatus::values())],
            'customer_id' => ['nullable', 'ulid', 'exists:customers,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'min_total' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            // The upper bound is usable on its own; gte only applies with a minimum.
            'max_total' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', Rule::when($this->filled('min_total'), ['gte:min_total'])],
            'sort' => ['nullable', Rule::in(['order_number', '-order_number', 'status', '-status', 'customer_name', '-customer_name', 'total_amount', '-total_amount', 'items_count', '-items_count', 'created_at', '-created_at'])],
            'per_page' => self::paginationRules(),
        ];
    }

    /**
     * Keep the range error field-oriented rather than echoing the bound value.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_total.gte' => 'The max total field must be greater than or equal to min total.',
        ];
    }

    /**
     * Describe order list query parameters for Scribe examples.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'search' => ['description' => 'Filter: search by order number.', 'example' => 'ORD-'],
            'status' => ['description' => 'Filter: order workflow status. Allowed: pending, confirmed, completed, cancelled.', 'example' => 'completed'],
            'customer_id' => ['description' => 'Filter: customer ULID.', 'example' => '01m2swhmj7dps93xge6qjdzjae'],
            'from' => ['description' => 'Filter: created_at from date, inclusive.', 'example' => '2026-09-01'],
            'to' => ['description' => 'Filter: created_at to date, inclusive.', 'example' => '2026-09-30'],
            'min_total' => ['description' => 'Filter: minimum order total in dollars.', 'example' => '50.00'],
            'max_total' => ['description' => 'Filter: maximum order total in dollars.', 'example' => '500.00'],
            'sort' => ['description' => 'Sortable: order_number, status, customer_name, total_amount, items_count, created_at. Prefix with - for descending, for example -total_amount.', 'example' => '-total_amount'],
            ...$this->paginationQueryParameter('orders'),
        ];
    }
}
