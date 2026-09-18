<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_list_and_show_include_order_metrics(): void
    {
        Passport::actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['name' => 'Primary Buyer']);
        $otherCustomer = Customer::factory()->create(['name' => 'Other Buyer']);

        Order::factory()->for($customer)->create(['total_amount' => 12500, 'status' => 'completed']);
        Order::factory()->for($customer)->create(['total_amount' => 2500, 'status' => 'pending']);
        Order::factory()->for($otherCustomer)->create(['total_amount' => 500, 'status' => 'completed']);

        $this->getJson('/api/v1/customers?search=primary')
            ->assertOk()
            ->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('data.0.total_order_amount', '150.00')
            ->assertJsonPath('data.0.completed_orders_count', 1)
            ->assertJsonPath('data.0.customer_value_rank', 1);

        $this->getJson('/api/v1/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.orders_count', 2)
            ->assertJsonPath('data.total_order_amount', '150.00')
            ->assertJsonPath('data.completed_orders_count', 1)
            ->assertJsonPath('data.customer_value_rank', 1);
    }
}
