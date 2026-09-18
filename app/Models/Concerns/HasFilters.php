<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\QueryFilters\QueryFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds a reusable model scope for pilter-style query filtering classes.
 */
trait HasFilters
{
    /**
     * Apply the model's configured query filter class to the builder.
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $filterClass = $this->filterClass ?? null;

        if (! $filterClass || ! is_a($filterClass, QueryFilter::class, true)) {
            return $query;
        }

        return (new $filterClass($filters))->apply($query);
    }
}
