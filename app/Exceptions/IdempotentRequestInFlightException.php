<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when a duplicate request arrives while the original is still running.
 *
 * Returning 409 with Retry-After is safer than racing the in-flight request:
 * the client retries once the first attempt has stored its response.
 */
class IdempotentRequestInFlightException extends RuntimeException
{
    /**
     * Create the in-flight exception with a client-actionable message.
     */
    public function __construct()
    {
        parent::__construct('A request with this Idempotency-Key is still being processed. Retry shortly.');
    }

    /**
     * Render the exception as a 409 with a Retry-After hint.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return ApiResponse::error($this->getMessage(), 409, [
            'Idempotency-Key' => [$this->getMessage()],
        ])->header('Retry-After', '1');
    }
}
