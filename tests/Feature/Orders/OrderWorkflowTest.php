<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Covers the order state machine and the stock movements each transition makes.
 */
class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Customer $customer;

    private User $user;

    /**
     * Seed one product with stock and an authenticated API client.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);

        $category = Category::factory()->create();
        $this->product = Product::factory()->for($category)->create([
            'price' => 1000,
            'status' => 'active',
        ]);
        InventoryItem::factory()->for($this->product)->create([
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    public function test_reservation_holds_stock_without_moving_physical_units(): void
    {
        $this->createOrder(quantity: 4);

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 4,
        ]);

        // Availability is what a further order may draw from.
        $this->getJson('/api/v1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_on_hand', 10)
            ->assertJsonPath('data.0.quantity_reserved', 4)
            ->assertJsonPath('data.0.available_quantity', 6);
    }

    public function test_a_second_order_can_only_draw_on_unreserved_stock(): void
    {
        $this->createOrder(quantity: 8);

        // 10 on hand, 8 reserved, so only 2 remain available.
        $this->postOrder(quantity: 3, key: 'second-order')
            ->assertConflict()
            ->assertJsonPath('message', 'Insufficient stock for one or more products.')
            ->assertJsonPath('errors.items.0.requested', 3)
            ->assertJsonPath('errors.items.0.available', 2);

        $this->postOrder(quantity: 2, key: 'third-order')->assertCreated();

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 10,
        ]);
    }

    public function test_completing_an_order_converts_the_reservation_into_a_stock_decrement(): void
    {
        $orderId = $this->createOrder(quantity: 4);

        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        // Confirming does not ship anything, so the balances do not move.
        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 4,
        ]);

        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // Fulfilment is where units physically leave and the hold is released.
        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 6,
            'quantity_reserved' => 0,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'order_id' => $orderId,
            'type' => InventoryMovementType::OrderFulfilled->value,
            'quantity_delta' => -4,
            'quantity_after' => 6,
            'reserved_delta' => -4,
            'reserved_after' => 0,
        ]);
    }

    public function test_cancelling_an_order_releases_its_reservation_and_records_a_movement(): void
    {
        $orderId = $this->createOrder(quantity: 4);

        $this->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'order_id' => $orderId,
            'type' => InventoryMovementType::ReservationReleased->value,
            'reserved_delta' => -4,
            'reserved_after' => 0,
        ]);
    }

    public function test_cancelling_twice_releases_the_reservation_only_once(): void
    {
        $orderId = $this->createOrder(quantity: 4);

        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk();
        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk();

        // A repeated cancel must not fabricate stock.
        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
        ]);

        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_the_status_endpoint_cannot_be_used_to_cancel_an_order(): void
    {
        $orderId = $this->createOrder(quantity: 4);

        // Cancelling here would skip the stock release, so it is rejected.
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'cancelled'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => OrderStatus::Pending->value,
        ]);
    }

    public function test_the_workflow_rejects_transitions_that_skip_or_reverse_states(): void
    {
        $orderId = $this->createOrder(quantity: 1);

        // pending cannot jump straight to completed.
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'An order cannot move from pending to completed.');

        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'confirmed'])->assertOk();
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'completed'])->assertOk();

        // Completed is terminal: it cannot be reopened or cancelled.
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'confirmed'])
            ->assertStatus(422)
            ->assertJsonPath('errors.allowed_transitions', []);

        $this->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'An order cannot move from completed to cancelled.');

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 9,
            'quantity_reserved' => 0,
        ]);
    }

    public function test_status_history_records_every_transition(): void
    {
        $orderId = $this->createOrder(quantity: 1);
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'confirmed', 'note' => 'Stock checked.'])->assertOk();

        $this->getJson("/api/v1/orders/{$orderId}/history")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.from_status', 'pending')
            ->assertJsonPath('data.0.to_status', 'confirmed')
            ->assertJsonPath('data.0.note', 'Stock checked.')
            ->assertJsonPath('data.1.from_status', null)
            ->assertJsonPath('data.1.to_status', 'pending');
    }

    public function test_a_client_cannot_read_or_act_on_another_clients_order(): void
    {
        $orderId = $this->createOrder(quantity: 1);

        Passport::actingAs(User::factory()->create());

        $this->getJson("/api/v1/orders/{$orderId}")->assertForbidden();
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertForbidden();

        // The list endpoint is scoped at the query level, not just by policy.
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_an_administrator_can_act_on_any_order(): void
    {
        $orderId = $this->createOrder(quantity: 1);

        Passport::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson("/api/v1/orders/{$orderId}")->assertOk();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_an_order_cannot_be_placed_for_an_inactive_product(): void
    {
        $this->product->update(['status' => 'archived']);

        $response = $this->postOrder(quantity: 1, key: 'archived-product')->assertStatus(422);

        // The error key contains dots, so it is read directly rather than
        // through a dot-notation JSON path.
        $this->assertSame(
            'One or more products are unavailable for ordering.',
            $response->json('errors')['items.0.product_id'][0],
        );
    }

    /**
     * Place an order through the API and return its id.
     */
    private function createOrder(int $quantity, string $key = 'workflow-key'): string
    {
        return $this->postOrder($quantity, $key)->assertCreated()->json('data.id');
    }

    /**
     * Post an order creation request.
     */
    private function postOrder(int $quantity, string $key): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => $quantity],
            ],
        ]);
    }
}
