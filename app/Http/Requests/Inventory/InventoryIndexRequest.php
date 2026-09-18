<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

/**
 * Validates inventory filtering, sorting, and pagination query parameters.
 */
class InventoryIndexRequest extends IndexRequest
{
    /**
     * Allow authenticated route middleware to decide access for this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return validation rules for inventory list query parameters.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Upper bounds are usable without their lower-bound partner: the
            // gte comparison only applies when a minimum was supplied.
            'product_id' => ['nullable', 'ulid', 'exists:products,id'],
            'min_quantity_on_hand' => ['nullable', 'integer', 'min:0'],
            'max_quantity_on_hand' => ['nullable', 'integer', 'min:0', Rule::when($this->filled('min_quantity_on_hand'), ['gte:min_quantity_on_hand'])],
            'min_quantity_reserved' => ['nullable', 'integer', 'min:0'],
            'max_quantity_reserved' => ['nullable', 'integer', 'min:0', Rule::when($this->filled('min_quantity_reserved'), ['gte:min_quantity_reserved'])],
            'min_available_quantity' => ['nullable', 'integer'],
            'max_available_quantity' => ['nullable', 'integer', Rule::when($this->filled('min_available_quantity'), ['gte:min_available_quantity'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['product_title', '-product_title', 'product_id', '-product_id', 'quantity_on_hand', '-quantity_on_hand', 'quantity_reserved', '-quantity_reserved', 'available_quantity', '-available_quantity', 'created_at', '-created_at'])],
            'per_page' => self::paginationRules(),
        ];
    }

    /**
     * Describe inventory list query parameters for Scribe examples.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'product_id' => ['description' => 'Filter: product ULID.', 'example' => '01m2swhmj7dps93xge6qjdzjae'],
            'min_quantity_on_hand' => ['description' => 'Filter: minimum quantity_on_hand.', 'example' => 10],
            'max_quantity_on_hand' => ['description' => 'Filter: maximum quantity_on_hand.', 'example' => 500],
            'min_quantity_reserved' => ['description' => 'Filter: minimum quantity_reserved.', 'example' => 0],
            'max_quantity_reserved' => ['description' => 'Filter: maximum quantity_reserved.', 'example' => 50],
            'min_available_quantity' => ['description' => 'Filter: minimum available quantity (on hand minus reserved).', 'example' => 1],
            'max_available_quantity' => ['description' => 'Filter: maximum available quantity (on hand minus reserved).', 'example' => 500],
            'from' => ['description' => 'Filter: created_at from date, inclusive.', 'example' => '2026-09-01'],
            'to' => ['description' => 'Filter: created_at to date, inclusive.', 'example' => '2026-09-30'],
            'sort' => ['description' => 'Sortable: product_title, product_id, quantity_on_hand, quantity_reserved, available_quantity, created_at. Prefix with - for descending, for example -quantity_on_hand.', 'example' => '-quantity_on_hand'],
            ...$this->paginationQueryParameter('inventory records'),
        ];
    }
}
