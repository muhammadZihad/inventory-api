<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pages the catalog, attaching sales metrics the cheapest way available.
 *
 * Which of the two strategies to use is a data-access decision, not an HTTP
 * one, so it lives here rather than in the controller:
 *
 *  - Sorting by a metric column has to happen before pagination, so that case
 *    joins the materialised metrics table and lets the optimiser drive from the
 *    sorted metric index.
 *  - Every other request pages on an indexed column first and then fetches
 *    metrics for just that page, which keeps the join off the paginator's count
 *    query and off the other 100k+ products.
 */
class ProductListQuery
{
    /**
     * Page the catalog for the given validated filters.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Product>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->sortsByMetric($filters)
            ? $this->paginateSortedByMetric($filters, $perPage)
            : $this->paginateAndAttachMetrics($filters, $perPage);
    }

    /**
     * Build the query shared by every read, with relations eager-loaded.
     *
     * @return Builder<Product>
     */
    public function baseQuery(): Builder
    {
        return Product::query()
            ->with(['category' => fn ($query) => $query->withCount('products'), 'inventory']);
    }

    /**
     * Determine whether the requested sort needs the metrics join up front.
     *
     * @param  array<string, mixed>  $filters
     */
    private function sortsByMetric(array $filters): bool
    {
        return in_array(
            ltrim((string) ($filters['sort'] ?? ''), '-'),
            Product::SALES_METRIC_COLUMNS,
            true,
        );
    }

    /**
     * Join the metrics table so the database can sort on it.
     *
     * No filter touches a metric column, so the row count is taken from the
     * unjoined query; passing it to paginate() suppresses the count query,
     * which would otherwise repeat the join to produce a number the join
     * cannot change.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Product>
     */
    private function paginateSortedByMetric(array $filters, int $perPage): LengthAwarePaginator
    {
        $total = $this->baseQuery()->filter($filters)->toBase()->getCountForPagination();

        return $this->baseQuery()
            ->withSalesMetrics()
            ->filter($filters)
            ->paginate($perPage, ['*'], 'page', null, $total);
    }

    /**
     * Page first, then look up metrics for only the rows being returned.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Product>
     */
    private function paginateAndAttachMetrics(array $filters, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->baseQuery()->filter($filters)->paginate($perPage);
        $metrics = Product::salesMetricsFor($paginator->getCollection()->pluck('id')->all());

        $paginator->getCollection()->each(function (Product $product) use ($metrics): void {
            $product->forceFill($metrics[$product->id] ?? [
                'units_sold' => 0,
                'orders_count' => 0,
                'gross_sales' => 0,
                'sales_rank' => null,
            ])->syncOriginal();
        });

        return $paginator;
    }
}
