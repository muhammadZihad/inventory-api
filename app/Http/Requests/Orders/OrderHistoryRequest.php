<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Http\Requests\IndexRequest;

/**
 * Validates pagination for the order status history endpoint.
 */
class OrderHistoryRequest extends IndexRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => self::paginationRules(),
        ];
    }

    /**
     * Describe history query parameters for Scribe examples.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return $this->paginationQueryParameter('history entries');
    }
}
