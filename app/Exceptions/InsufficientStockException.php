<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when an order cannot reserve the requested product quantities.
 */
class InsufficientStockException extends RuntimeException
{
    /**
     * Create the stock failure exception with the shortfall details.
     *
     * @param  array<int, array{product_id: string, requested: int, available: int}>  $shortfalls
     */
    public function __construct(private readonly array $shortfalls = [])
    {
        parent::__construct('Insufficient stock for one or more products.');
    }

    /**
     * Return the per-product shortfall detail for API clients.
     *
     * @return array<int, array{product_id: string, requested: int, available: int}>
     */
    public function shortfalls(): array
    {
        return $this->shortfalls;
    }

    /**
     * Render the exception as a 409 conflict so any caller responds consistently.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return ApiResponse::error($this->getMessage(), 409, ['items' => $this->shortfalls]);
    }
}
