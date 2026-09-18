<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Pages customers, attaching order metrics the cheapest way available.
 *
 * Mirrors {@see ProductListQuery}: sorting by a metric column joins the
 * aggregate before paginating, anything else pages on an indexed column and
 * then fetches metrics for just that page.
 */
class CustomerListQuery
{
    /**
     * Page customers for the given validated filters.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Customer>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sortColumn = ltrim((string) ($filters['sort'] ?? ''), '-');

        if (in_array($sortColumn, Customer::ORDER_METRIC_COLUMNS, true)) {
            // The aggregate join cannot change the row count, so the count
            // query is computed without it.
            $total = Customer::query()->filter($filters)->toBase()->getCountForPagination();

            return Customer::query()
                ->withOrderMetrics()
                ->filter($filters)
                ->paginate($perPage, ['*'], 'page', null, $total);
        }

        $paginator = Customer::query()->filter($filters)->paginate($perPage);
        $metrics = Customer::orderMetricsFor($paginator->getCollection()->pluck('id')->all());

        $paginator->getCollection()->each(function (Customer $customer) use ($metrics): void {
            $customer->forceFill($metrics[$customer->id] ?? [
                'orders_count' => 0,
                'completed_orders_count' => 0,
                'total_order_amount' => 0,
                'customer_value_rank' => null,
            ])->syncOriginal();
        });

        return $paginator;
    }
}
