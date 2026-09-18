<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderReadableNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_include_customer_names_and_line_item_product_names(): void
    {
        Passport::actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['name' => 'Amina Rahman']);
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['name' => 'Wireless Keyboard', 'price' => 2500]);
        InventoryItem::factory()->for($product)->create();
        $order = Order::factory()->for($customer)->create([
            'order_number' => 'ORD-NAMES-001',
            'total_amount' => 2500,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 2500,
            'line_total' => 2500,
        ]);

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.customer_name', 'Amina Rahman')
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.name', 'Amina Rahman')
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.product_name', 'Wireless Keyboard')
            ->assertJsonPath('data.items.0.product.id', $product->id)
            ->assertJsonPath('data.items.0.product.name', 'Wireless Keyboard');
    }

    public function test_orders_can_sort_by_visible_customer_and_item_columns(): void
    {
        Passport::actingAs(User::factory()->create());

        $zara = Customer::factory()->create(['name' => 'Zara Buyer']);
        $acme = Customer::factory()->create(['name' => 'Acme Buyer']);

        $zaraOrder = Order::factory()->for($zara)->create([
            'order_number' => 'ORD-SORT-002',
            'total_amount' => 5000,
        ]);
        $acmeOrder = Order::factory()->for($acme)->create([
            'order_number' => 'ORD-SORT-001',
            'total_amount' => 2500,
        ]);

        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['price' => 1000]);
        OrderItem::query()->create(['order_id' => $zaraOrder->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000]);
        OrderItem::query()->create(['order_id' => $zaraOrder->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000]);
        OrderItem::query()->create(['order_id' => $acmeOrder->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000]);

        $this->getJson('/api/v1/orders?sort=customer_name&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.customer_name', 'Acme Buyer')
            ->assertJsonPath('data.1.customer_name', 'Zara Buyer');

        $this->getJson('/api/v1/orders?sort=-items_count&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', 'ORD-SORT-002')
            ->assertJsonPath('data.0.items_count', 2);
    }
}
