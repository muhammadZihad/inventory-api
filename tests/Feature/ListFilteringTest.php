<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ListFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_index_can_search_filter_and_sort(): void
    {
        Passport::actingAs(User::factory()->create());

        Category::factory()->create(['name' => 'Audio Gear', 'slug' => 'audio-gear', 'status' => 'active']);
        Category::factory()->create(['name' => 'Audio Clearance', 'slug' => 'audio-clearance', 'status' => 'archived']);
        Category::factory()->create(['name' => 'Books', 'slug' => 'books', 'status' => 'active']);

        $this->getJson('/api/v1/categories?search=audio&status=active&sort=name&per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Audio Gear')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_customer_index_can_search_and_sort(): void
    {
        Passport::actingAs(User::factory()->create());

        Customer::factory()->create(['name' => 'Zara Retail', 'email' => 'zara@example.com']);
        Customer::factory()->create(['name' => 'Acme Retail', 'email' => 'acme@example.com']);
        Customer::factory()->create(['name' => 'Book Buyer', 'email' => 'buyer@example.com']);

        $this->getJson('/api/v1/customers?search=retail&sort=name&per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Acme Retail')
            ->assertJsonPath('data.1.name', 'Zara Retail')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_inventory_index_can_filter_by_product_and_sort_quantities(): void
    {
        Passport::actingAs(User::factory()->create());

        $category = Category::factory()->create();
        $keyboard = Product::factory()->for($category)->create(['name' => 'Keyboard Pro', 'sku' => 'KEY-100']);
        $mouse = Product::factory()->for($category)->create(['name' => 'Mouse Mini', 'sku' => 'MOU-100']);
        InventoryItem::factory()->for($keyboard)->create(['quantity_on_hand' => 20, 'quantity_reserved' => 3]);
        InventoryItem::factory()->for($mouse)->create(['quantity_on_hand' => 5, 'quantity_reserved' => 1]);

        $this->getJson('/api/v1/inventory?product_id='.$keyboard->id.'&sort=-quantity_on_hand&per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.quantity_on_hand', 20)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_order_index_can_filter_by_status_customer_date_range_and_sort_total(): void
    {
        Passport::actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['name' => 'Primary Buyer']);
        $otherCustomer = Customer::factory()->create(['name' => 'Other Buyer']);

        Order::factory()->for($customer)->create([
            'order_number' => 'ORD-FILTER-001',
            'status' => 'completed',
            'total_amount' => 7500,
            'created_at' => '2026-09-10 10:00:00',
        ]);
        Order::factory()->for($customer)->create([
            'order_number' => 'ORD-FILTER-002',
            'status' => 'completed',
            'total_amount' => 2500,
            'created_at' => '2026-09-11 10:00:00',
        ]);
        Order::factory()->for($otherCustomer)->create([
            'order_number' => 'ORD-FILTER-003',
            'status' => 'pending',
            'total_amount' => 9900,
            'created_at' => '2026-09-11 10:00:00',
        ]);

        $this->getJson('/api/v1/orders?status=completed&customer_id='.$customer->id.'&from=2026-09-10&to=2026-09-12&sort=-total_amount&per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.order_number', 'ORD-FILTER-001')
            ->assertJsonPath('data.1.order_number', 'ORD-FILTER-002')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_visible_table_columns_are_safe_sort_keys(): void
    {
        Passport::actingAs(User::factory()->create());

        $audio = Category::factory()->create(['name' => 'Audio', 'slug' => 'audio']);
        $books = Category::factory()->create(['name' => 'Books', 'slug' => 'books']);

        $speaker = Product::factory()->for($audio)->create(['name' => 'Speaker', 'sku' => 'SPK-100', 'status' => 'active', 'price' => 5000]);
        $book = Product::factory()->for($books)->create(['name' => 'Book', 'sku' => 'BOO-100', 'status' => 'draft', 'price' => 1500]);
        InventoryItem::factory()->for($speaker)->create(['quantity_on_hand' => 40, 'quantity_reserved' => 5]);
        InventoryItem::factory()->for($book)->create(['quantity_on_hand' => 10, 'quantity_reserved' => 1]);

        $customer = Customer::factory()->create(['name' => 'Acme Buyer', 'email' => 'acme@example.com', 'phone' => '555-0100']);
        $order = Order::factory()->for($customer)->create(['order_number' => 'ORD-VISIBLE-001', 'status' => 'completed', 'total_amount' => 5000]);
        $order->items()->create(['product_id' => $speaker->id, 'quantity' => 2, 'unit_price' => 2500, 'line_total' => 5000]);

        $sortsByEndpoint = [
            '/api/v1/products' => ['name', 'sku', 'status', 'price', 'category_name', 'stock', 'units_sold', 'gross_sales', 'sales_rank'],
            '/api/v1/orders' => ['order_number', 'status', 'customer_name', 'total_amount', 'items_count', 'created_at'],
            '/api/v1/categories' => ['name', 'slug', 'status', 'products_count'],
            '/api/v1/customers' => ['name', 'email', 'phone', 'orders_count', 'total_order_amount', 'customer_value_rank', 'created_at'],
            '/api/v1/inventory' => ['product_title', 'product_id', 'quantity_on_hand', 'quantity_reserved', 'available_quantity'],
        ];

        foreach ($sortsByEndpoint as $endpoint => $sorts) {
            foreach ($sorts as $sort) {
                $this->getJson($endpoint.'?sort='.$sort.'&per_page=5')
                    ->assertOk()
                    ->assertJsonPath('success', true);

                $this->getJson($endpoint.'?sort=-'.$sort.'&per_page=5')
                    ->assertOk()
                    ->assertJsonPath('success', true);
            }
        }
    }
}
