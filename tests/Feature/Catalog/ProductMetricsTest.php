<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\SalesMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProductMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_and_show_include_sales_metrics(): void
    {
        Passport::actingAs(User::factory()->create());

        $category = Category::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->for($category)->create(['name' => 'Metric Keyboard', 'price' => 1000]);
        $otherProduct = Product::factory()->for($category)->create(['name' => 'Metric Mouse', 'price' => 500]);
        InventoryItem::factory()->for($product)->create();
        InventoryItem::factory()->for($otherProduct)->create();

        $order = Order::factory()->for($customer)->create(['total_amount' => 2500]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1000, 'line_total' => 2000]);
        $order->items()->create(['product_id' => $otherProduct->id, 'quantity' => 1, 'unit_price' => 500, 'line_total' => 500]);

        // Sales metrics are materialised and maintained on the order write
        // path. These rows were inserted straight into the database, so they
        // need the same rebuild the seeder and any bulk import would run.
        app(SalesMetricsService::class)->rebuild();

        $this->getJson('/api/v1/products?search=Metric&sort=-price')
            ->assertOk()
            ->assertJsonPath('data.0.units_sold', 2)
            ->assertJsonPath('data.0.gross_sales', '20.00')
            ->assertJsonPath('data.0.orders_count', 1)
            ->assertJsonPath('data.0.sales_rank', 1);

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.units_sold', 2)
            ->assertJsonPath('data.gross_sales', '20.00')
            ->assertJsonPath('data.orders_count', 1)
            ->assertJsonPath('data.sales_rank', 1);
    }

    public function test_placing_an_order_updates_sales_metrics_without_a_rebuild(): void
    {
        Passport::actingAs(User::factory()->create());

        $category = Category::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->for($category)->create(['price' => 1000, 'status' => 'active']);
        InventoryItem::factory()->for($product)->create(['quantity_on_hand' => 20, 'quantity_reserved' => 0]);

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.units_sold', 0)
            ->assertJsonPath('data.gross_sales', '0.00');

        $this->withHeader('Idempotency-Key', 'metrics-key')
            ->postJson('/api/v1/orders', [
                'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertCreated();

        // The totals move on the write path itself, with no rebuild in between.
        $this->getJson('/api/v1/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.units_sold', 3)
            ->assertJsonPath('data.orders_count', 1)
            ->assertJsonPath('data.gross_sales', '30.00');

        // A second order accumulates rather than replacing.
        $this->withHeader('Idempotency-Key', 'metrics-key-2')
            ->postJson('/api/v1/orders', [
                'customer_id' => $customer->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertCreated();

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.units_sold', 5)
            ->assertJsonPath('data.orders_count', 2)
            ->assertJsonPath('data.gross_sales', '50.00');
    }
}
