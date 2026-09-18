<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_creation_reserves_stock_and_replays_idempotent_requests(): void
    {
        Passport::actingAs(User::factory()->create());

        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['price' => 1000]);
        InventoryItem::factory()->for($product)->create([
            'quantity_on_hand' => 5,
            'quantity_reserved' => 0,
        ]);
        $customer = Customer::factory()->create();

        $payload = [
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ];

        $first = $this
            ->withHeader('Idempotency-Key', 'order-key-001')
            ->postJson('/api/v1/orders', $payload);

        $first
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Order created successfully.')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_amount', '30.00')
            ->assertJsonPath('data.items.0.unit_price', '10.00')
            ->assertJsonPath('data.items.0.line_total', '30.00');

        $second = $this
            ->withHeader('Idempotency-Key', 'order-key-001')
            ->postJson('/api/v1/orders', $payload);

        // A replay returns the original response verbatim: same status, same
        // body, flagged with the replay header.
        $second
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertExactJson($first->json());

        // Reserving commits stock without moving physical units: on-hand is
        // unchanged and the reservation is counted once, not twice.
        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $product->id,
            'quantity_on_hand' => 5,
            'quantity_reserved' => 3,
        ]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', [
            'id' => $first->json('data.id'),
            'total_amount' => '30.00',
        ]);
    }

    public function test_order_creation_prevents_overselling(): void
    {
        Passport::actingAs(User::factory()->create());

        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['price' => 1000]);
        InventoryItem::factory()->for($product)->create([
            'quantity_on_hand' => 2,
            'quantity_reserved' => 0,
        ]);
        $customer = Customer::factory()->create();

        $this
            ->withHeader('Idempotency-Key', 'order-key-002')
            ->postJson('/api/v1/orders', [
                'customer_id' => $customer->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 3],
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Insufficient stock for one or more products.');

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $product->id,
            'quantity_on_hand' => 2,
            'quantity_reserved' => 0,
        ]);
    }
}
