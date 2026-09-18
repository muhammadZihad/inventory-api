<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Builds the project-wide JSON response envelopes for API endpoints.
 *
 * Envelope builders are split from payload builders so that a cached endpoint
 * can store the plain array payload — which serialises cleanly into any cache
 * driver — and turn it into a response on the way out.
 */
class ApiResponse
{
    /**
     * Return a successful non-paginated JSON response.
     */
    public static function success(mixed $data = null, string $message = 'OK.', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Return a successful resource-created JSON response.
     */
    public static function created(mixed $data, string $message): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * Return a paginated JSON response with resource data and pagination metadata.
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resourceClass, string $message = 'OK.'): JsonResponse
    {
        return self::fromPayload(self::paginatedPayload($paginator, $resourceClass, $message));
    }

    /**
     * Build the cacheable array payload for a paginated response.
     *
     * Only the current page is serialised, so response size stays bounded by
     * per_page rather than by the size of the table.
     *
     * @param  class-string<JsonResource>  $resourceClass
     * @return array<string, mixed>
     */
    public static function paginatedPayload(LengthAwarePaginator $paginator, string $resourceClass, string $message = 'OK.'): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $resourceClass::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * Turn a previously built (and possibly cached) payload into a response.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }

    /**
     * Return a standardized error JSON response.
     */
    public static function error(string $message, int $status, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
