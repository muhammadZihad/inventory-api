<?php

declare(strict_types=1);

namespace App\QueryFilters;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base class for safe request-driven search, filters, ranges, and sorting.
 */
abstract class QueryFilter
{
    /** @var array<string, mixed> */
    protected array $filters;

    /** @var string[] */
    protected array $searchColumns = [];

    /** @var array<string, string> */
    protected array $exactFilters = [];

    /** @var string[] */
    protected array $sortableColumns = [];

    /** @var array<string, string> */
    protected array $sortAliases = [];

    /** @var array<string, array{min: string, max: string}> */
    protected array $numericRangeFilters = [];

    protected ?string $moneyRangeColumn = null;

    protected string $moneyRangePrefix = 'price';

    protected bool $supportsCreatedAtRange = false;

    /**
     * Store only non-empty filter values from the validated request input.
     */
    public function __construct(array $filters)
    {
        $this->filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Apply all supported filter operations to the query builder.
     */
    public function apply(Builder $query): Builder
    {
        $this->applySearch($query);
        $this->applyExactFilters($query);
        $this->applyMoneyRange($query);
        $this->applyNumericRanges($query);
        $this->applyCreatedAtRange($query);
        $this->applySort($query);

        return $query;
    }

    /**
     * Apply a partial text search across configured columns.
     */
    protected function applySearch(Builder $query): void
    {
        $search = $this->filters['search'] ?? null;

        if (! $search || $this->searchColumns === []) {
            return;
        }

        $query->where(function (Builder $query) use ($search): void {
            foreach ($this->searchColumns as $column) {
                $query->orWhere($column, 'like', '%'.$search.'%');
            }
        });
    }

    /**
     * Apply whitelisted exact-match filters.
     */
    protected function applyExactFilters(Builder $query): void
    {
        foreach ($this->exactFilters as $input => $column) {
            if (array_key_exists($input, $this->filters)) {
                $query->where($column, $this->filters[$input]);
            }
        }
    }

    /**
     * Apply money range filters after normalizing request dollars for database comparison.
     */
    protected function applyMoneyRange(Builder $query): void
    {
        if (! $this->moneyRangeColumn) {
            return;
        }

        $minKey = 'min_'.$this->moneyRangePrefix;
        $maxKey = 'max_'.$this->moneyRangePrefix;

        if (array_key_exists($minKey, $this->filters)) {
            $query->where($this->moneyRangeColumn, '>=', Money::centsToDollars(Money::dollarsToCents($this->filters[$minKey])));
        }

        if (array_key_exists($maxKey, $this->filters)) {
            $query->where($this->moneyRangeColumn, '<=', Money::centsToDollars(Money::dollarsToCents($this->filters[$maxKey])));
        }
    }

    /**
     * Apply numeric min and max range filters for configured columns.
     */
    protected function applyNumericRanges(Builder $query): void
    {
        foreach ($this->numericRangeFilters as $column => $keys) {
            if (array_key_exists($keys['min'], $this->filters)) {
                $query->where($column, '>=', $this->filters[$keys['min']]);
            }

            if (array_key_exists($keys['max'], $this->filters)) {
                $query->where($column, '<=', $this->filters[$keys['max']]);
            }
        }
    }

    /**
     * Apply created_at date range filtering when supported by the model filter.
     *
     * Bounds are expanded to timestamps and compared against the bare column.
     * Wrapping created_at in DATE() would make every index on it unusable.
     */
    protected function applyCreatedAtRange(Builder $query): void
    {
        if (! $this->supportsCreatedAtRange) {
            return;
        }

        if (array_key_exists('from', $this->filters)) {
            $query->where('created_at', '>=', CarbonImmutable::parse($this->filters['from'])->startOfDay());
        }

        if (array_key_exists('to', $this->filters)) {
            $query->where('created_at', '<=', CarbonImmutable::parse($this->filters['to'])->endOfDay());
        }
    }

    /**
     * Apply safe sorting, falling back to newest records for unsupported columns.
     */
    protected function applySort(Builder $query): void
    {
        $sort = $this->filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, $this->sortableColumns, true)) {
            $column = 'created_at';
            $direction = 'desc';
        }

        $query->orderBy($this->sortAliases[$column] ?? $column, $direction);
    }
}
