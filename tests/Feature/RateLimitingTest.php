<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Covers the named rate limiters and the envelope a throttled response uses.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    /** Requests allowed per minute by the 'orders' limiter. */
    private const ORDER_LIMIT = 30;

    /** Requests allowed per minute by the 'auth' limiter. */
    private const AUTH_LIMIT = 20;

    protected function setUp(): void
    {
        parent::setUp();

        // Limiter hit counters live in the cache, so clearing it keeps counts
        // from one test out of the next.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_order_creation_is_throttled_by_the_orders_limiter(): void
    {
        Passport::actingAs(User::factory()->create());

        $product = Product::factory()->for(Category::factory())->create(['price' => 100, 'status' => 'active']);
        InventoryItem::factory()->for($product)->create([
            'quantity_on_hand' => 200,
            'quantity_reserved' => 0,
        ]);
        $customer = Customer::factory()->create();

        $payload = [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; $attempt++) {
            // A distinct key per attempt keeps the idempotency middleware from
            // replaying, so every request really consumes limiter budget.
            $this->withHeader('Idempotency-Key', 'rate-limit-'.$attempt)
                ->postJson('/api/v1/orders', $payload)
                ->assertCreated();
        }

        $throttled = $this->withHeader('Idempotency-Key', 'rate-limit-over')
            ->postJson('/api/v1/orders', $payload);

        $throttled
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many requests.')
            ->assertJsonPath('errors', null);

        // The request never reached the controller, so no extra order exists.
        $this->assertDatabaseCount('orders', self::ORDER_LIMIT);
    }

    public function test_login_is_throttled_by_the_auth_limiter(): void
    {
        User::factory()->create(['email' => 'throttled@example.com']);

        $credentials = ['email' => 'throttled@example.com', 'password' => 'wrong-password'];

        for ($attempt = 1; $attempt <= self::AUTH_LIMIT; $attempt++) {
            $this->postJson('/api/v1/auth/login', $credentials)
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Invalid credentials.');
        }

        $this->postJson('/api/v1/auth/login', $credentials)
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many requests.')
            ->assertJsonPath('errors', null);
    }

    public function test_a_throttled_response_carries_a_retry_after_header(): void
    {
        $credentials = ['email' => 'nobody@example.com', 'password' => 'wrong-password'];

        for ($attempt = 1; $attempt <= self::AUTH_LIMIT; $attempt++) {
            $this->postJson('/api/v1/auth/login', $credentials)->assertUnprocessable();
        }

        $throttled = $this->postJson('/api/v1/auth/login', $credentials)->assertStatus(429);

        $throttled->assertHeader('Retry-After');
        // Retry-After must be a positive number of seconds for a client to act on.
        $this->assertGreaterThan(0, (int) $throttled->headers->get('Retry-After'));
    }
}
