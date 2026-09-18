<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when an idempotency key is replayed with a different request payload.
 */
class IdempotencyConflictException extends RuntimeException
{
    /**
     * Create the conflict exception with a client-actionable message.
     */
    public function __construct(string $message = 'This Idempotency-Key was already used with a different request payload.')
    {
        parent::__construct($message);
    }

    /**
     * Render the exception as a 409 conflict.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return ApiResponse::error($this->getMessage(), 409, [
            'Idempotency-Key' => [$this->getMessage()],
        ]);
    }
}
