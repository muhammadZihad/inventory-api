<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Produces the order summary aggregates behind a read-through cache.
 */
interface OrderReports
{
    /**
     * Return the cached order summary for the given filters.
     *
     * @param  array{status?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array;
}
