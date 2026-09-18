<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Covers the aggregate figures and filter validation of the order summary report.
 */
class OrderReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Passport::actingAs(User::factory()->create());
    }

    public function test_summary_counts_and_totals_exclude_cancelled_orders_from_sales(): void
    {
        $this->order('pending', 10000);
        $this->order('confirmed', 20000);
        $this->order('completed', 30000);
        $this->order('completed', 40000);
        $this->order('cancelled', 50000);

        $this->getJson('/api/v1/orders/reports/summary')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Order report generated successfully.')
            // Every order is counted, including the cancelled one.
            ->assertJsonPath('data.orders_count', 5)
            // 100 + 200 + 300 + 400; the cancelled 500 contributes nothing.
            ->assertJsonPath('data.total_sales', '1000.00')
            // Average is sales over *all* orders counted: 1000.00 / 5.
            ->assertJsonPath('data.average_order_value', '200.00')
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.confirmed_count', 1)
            ->assertJsonPath('data.completed_count', 2)
            ->assertJsonPath('data.cancelled_count', 1);
    }

    public function test_summary_can_be_narrowed_to_a_single_status(): void
    {
        $this->order('pending', 10000);
        $this->order('completed', 30000);
        $this->order('completed', 40000);

        $this->getJson('/api/v1/orders/reports/summary?status=completed')
            ->assertOk()
            ->assertJsonPath('data.orders_count', 2)
            ->assertJsonPath('data.total_sales', '700.00')
            ->assertJsonPath('data.average_order_value', '350.00')
            ->assertJsonPath('data.completed_count', 2)
            ->assertJsonPath('data.pending_count', 0);
    }

    public function test_summary_filters_orders_by_date_range(): void
    {
        $this->travelTo('2026-01-05 09:00:00');
        $this->order('completed', 10000);

        $this->travelTo('2026-02-10 09:00:00');
        $this->order('completed', 20000);

        $this->travelTo('2026-03-15 09:00:00');
        $this->order('completed', 40000);

        $this->travelBack();

        $this->getJson('/api/v1/orders/reports/summary?from=2026-02-01&to=2026-02-28')
            ->assertOk()
            ->assertJsonPath('data.orders_count', 1)
            ->assertJsonPath('data.total_sales', '200.00');

        // Bounds are inclusive and expanded to whole days on both ends.
        $this->getJson('/api/v1/orders/reports/summary?from=2026-01-05&to=2026-03-15')
            ->assertOk()
            ->assertJsonPath('data.orders_count', 3)
            ->assertJsonPath('data.total_sales', '700.00');
    }

    public function test_an_unknown_status_filter_is_rejected(): void
    {
        $this->getJson('/api/v1/orders/reports/summary?status=shipped')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonValidationErrors('status');
    }

    public function test_a_to_date_before_the_from_date_is_rejected(): void
    {
        $this->getJson('/api/v1/orders/reports/summary?from=2026-02-01&to=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('to');
    }

    /**
     * Create one order at the given status; the total is supplied in cents.
     */
    private function order(string $status, int $totalInCents): Order
    {
        return Order::factory()->create([
            'status' => $status,
            'total_amount' => $totalInCents,
        ]);
    }
}
