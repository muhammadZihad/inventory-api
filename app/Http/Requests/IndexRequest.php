<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for list endpoints with centralized pagination behavior.
 */
abstract class IndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * Return the shared per_page validation rules for every list endpoint.
     *
     * @return array<int, string>
     */
    public static function paginationRules(): array
    {
        return ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE];
    }

    /**
     * Resolve the requested page size with the project default fallback.
     */
    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }

    /**
     * Return the shared Scribe query docs for the per_page parameter.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function paginationQueryParameter(string $resourceName): array
    {
        return [
            'per_page' => [
                'description' => sprintf('Number of %s per page. Allowed: 1-%d.', $resourceName, self::MAX_PER_PAGE),
                'example' => self::DEFAULT_PER_PAGE,
            ],
        ];
    }
}
