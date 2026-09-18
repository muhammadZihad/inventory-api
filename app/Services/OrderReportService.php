<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\OrderReports;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Computes order summary aggregates behind a read-through cache.
 *
 * All figures come from one grouped query rather than one query per metric,
 * so the report costs a single index scan regardless of how many counters it
 * exposes.
 */
class OrderReportService implements OrderReports
{
    /**
     * Bind the shared cache repository.
     */
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Return the cached order summary for the given filters.
     *
     * @param  array{status?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $filters = array_filter([
            'status' => $filters['status'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->cache->remember(
            CacheNamespace::Reports,
            ['report' => 'orders.summary', ...$filters],
            fn (): array => $this->compute($filters),
        );
    }

    /**
     * Run the aggregate query and shape the report payload.
     *
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    private function compute(array $filters): array
    {
        $cancelled = OrderStatus::Cancelled->value;

        /** @var object{orders_count: int|null, total_sales: string|float|null, pending_count: int|null, confirmed_count: int|null, completed_count: int|null, cancelled_count: int|null}|null $row */
        $row = $this->baseQuery($filters)
            ->toBase()
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status != ? THEN total_amount ELSE 0 END), 0) AS total_sales', [$cancelled])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_count', [OrderStatus::Pending->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS confirmed_count', [OrderStatus::Confirmed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_count', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled_count', [$cancelled])
            ->first();

        $ordersCount = (int) ($row->orders_count ?? 0);
        $totalSalesCents = Money::dollarsToCents($row->total_sales ?? 0);

        return [
            'orders_count' => $ordersCount,
            'total_sales' => Money::centsToDollars($totalSalesCents),
            'average_order_value' => Money::centsToDollars(
                $ordersCount > 0 ? intdiv($totalSalesCents, $ordersCount) : 0,
            ),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'confirmed_count' => (int) ($row->confirmed_count ?? 0),
            'completed_count' => (int) ($row->completed_count ?? 0),
            'cancelled_count' => (int) ($row->cancelled_count ?? 0),
        ];
    }

    /**
     * Build the filtered order query shared by every aggregate.
     *
     * Date bounds are bound as parameters and compared against the raw column
     * rather than wrapping it in DATE(), so the (created_at, status) index
     * stays usable.
     *
     * @param  array<string, string>  $filters
     * @return Builder<Order>
     */
    private function baseQuery(array $filters): Builder
    {
        return Order::query()
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status) => $query->where('status', $status),
            )
            ->when(
                $filters['from'] ?? null,
                fn (Builder $query, string $from) => $query->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay()),
            )
            ->when(
                $filters['to'] ?? null,
                fn (Builder $query, string $to) => $query->where('created_at', '<=', CarbonImmutable::parse($to)->endOfDay()),
            );
    }
}
