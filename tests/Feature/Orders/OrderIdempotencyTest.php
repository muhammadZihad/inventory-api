<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Covers the Idempotency-Key contract on POST /api/v1/orders.
 */
class OrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Passport::actingAs(User::factory()->create());

        $this->product = Product::factory()
            ->for(Category::factory())
            ->create(['price' => 1000, 'status' => 'active']);

        InventoryItem::factory()->for($this->product)->create([
            'quantity_on_hand' => 20,
            'quantity_reserved' => 0,
        ]);

        $this->customer = Customer::factory()->create();
    }

    public function test_order_creation_without_an_idempotency_key_is_rejected(): void
    {
        $this->postJson('/api/v1/orders', $this->payload(1))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath(
                'errors.Idempotency-Key.0',
                'The Idempotency-Key header is required for this request.',
            );

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_reusing_a_key_with_a_different_payload_returns_a_conflict(): void
    {
        $this->withHeader('Idempotency-Key', 'key-conflict')
            ->postJson('/api/v1/orders', $this->payload(2))
            ->assertCreated();

        // The stored request fingerprint no longer matches, so the middleware
        // refuses to replay rather than silently returning the wrong order.
        $this->withHeader('Idempotency-Key', 'key-conflict')
            ->postJson('/api/v1/orders', $this->payload(3))
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'errors.Idempotency-Key.0',
                'This Idempotency-Key was already used with a different request payload.',
            );

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_a_failed_request_releases_the_key_so_the_same_key_can_be_retried(): void
    {
        // Missing customer_id fails validation inside the middleware, which
        // must release its claim on the key.
        $this->withHeader('Idempotency-Key', 'key-retry')
            ->postJson('/api/v1/orders', ['items' => [['product_id' => $this->product->id, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', 'The customer id field is required.');

        $this->assertDatabaseCount('idempotency_keys', 0);

        $this->withHeader('Idempotency-Key', 'key-retry')
            ->postJson('/api/v1/orders', $this->payload(1))
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'false');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_two_different_keys_create_two_separate_orders(): void
    {
        $first = $this->withHeader('Idempotency-Key', 'key-a')
            ->postJson('/api/v1/orders', $this->payload(1))
            ->assertCreated();

        $second = $this->withHeader('Idempotency-Key', 'key-b')
            ->postJson('/api/v1/orders', $this->payload(1))
            ->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 2);

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_reserved' => 2,
        ]);
    }

    public function test_replaying_a_request_does_not_reserve_stock_twice(): void
    {
        $first = $this->withHeader('Idempotency-Key', 'key-replay')
            ->postJson('/api/v1/orders', $this->payload(4))
            ->assertCreated();

        foreach (range(1, 2) as $ignored) {
            $this->withHeader('Idempotency-Key', 'key-replay')
                ->postJson('/api/v1/orders', $this->payload(4))
                ->assertCreated()
                ->assertHeader('Idempotent-Replay', 'true')
                ->assertExactJson($first->json());
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 20,
            'quantity_reserved' => 4,
        ]);

        // A replay short-circuits before the action runs, so exactly one
        // reservation movement exists in the ledger.
        $this->assertSame(1, DB::table('inventory_movements')
            ->where('product_id', $this->product->id)
            ->where('type', 'order_reserved')
            ->count());
    }

    /**
     * Build a valid single-line order payload.
     *
     * @return array<string, mixed>
     */
    private function payload(int $quantity): array
    {
        return [
            'customer_id' => $this->customer->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => $quantity],
            ],
        ];
    }
}
