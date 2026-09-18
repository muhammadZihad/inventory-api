<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

/**
 * Validates customer filtering, sorting, and pagination query parameters.
 */
class CustomerIndexRequest extends IndexRequest
{
    /**
     * Allow authenticated route middleware to decide access for this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return validation rules for customer list query parameters.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['name', '-name', 'email', '-email', 'phone', '-phone', 'orders_count', '-orders_count', 'total_order_amount', '-total_order_amount', 'customer_value_rank', '-customer_value_rank', 'created_at', '-created_at'])],
            'per_page' => self::paginationRules(),
        ];
    }

    /**
     * Describe customer list query parameters for Scribe examples.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'search' => ['description' => 'Filter: search customer name, email, or phone.', 'example' => 'retail'],
            'from' => ['description' => 'Filter: created_at from date, inclusive.', 'example' => '2026-09-01'],
            'to' => ['description' => 'Filter: created_at to date, inclusive.', 'example' => '2026-09-30'],
            'sort' => ['description' => 'Sortable: name, email, phone, orders_count, total_order_amount, customer_value_rank, created_at. Prefix with - for descending, for example -total_order_amount.', 'example' => 'name'],
            ...$this->paginationQueryParameter('customers'),
        ];
    }
}
