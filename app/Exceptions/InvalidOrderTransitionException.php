<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OrderStatus;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when an order is asked to move to a status the workflow disallows.
 */
class InvalidOrderTransitionException extends RuntimeException
{
    /**
     * Describe the rejected transition and the statuses that were allowed.
     */
    public function __construct(
        private readonly OrderStatus $from,
        private readonly OrderStatus $to,
    ) {
        parent::__construct(sprintf(
            'An order cannot move from %s to %s.',
            $from->value,
            $to->value,
        ));
    }

    /**
     * Render the exception as a 422 so clients can correct the requested status.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return ApiResponse::error($this->getMessage(), 422, [
            'status' => [
                $this->getMessage(),
            ],
            'allowed_transitions' => array_map(
                fn (OrderStatus $status): string => $status->value,
                $this->from->allowedTransitions(),
            ),
        ]);
    }
}
