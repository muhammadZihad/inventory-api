<?php

declare(strict_types=1);

namespace Tests\Feature\Caching;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Covers the read-through cache on list/report endpoints and its invalidation.
 *
 * Caching is proven by mutating rows with the query builder, which bypasses
 * the events that drive invalidation: if a stale value comes back, the read
 * was served from cache rather than from the database.
 */
class CachedReadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The cache outlives the database between tests on a persistent store
        // such as Redis, so it is cleared explicitly rather than relying on the
        // array driver the test config happens to use.
        Cache::flush();

        // Catalog and stock writes are administrator operations.
        Passport::actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_product_index_is_served_from_cache_on_a_repeat_request(): void
    {
        $product = Product::factory()->for(Category::factory())->create(['name' => 'Original Name']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Original Name');

        DB::table('products')->where('id', $product->id)->update(['name' => 'Mutated Behind The Cache']);

        // The direct update fires no CatalogChanged event, so the cached page
        // is still valid as far as the application is concerned.
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Original Name');
    }

    public function test_updating_a_product_through_the_api_invalidates_the_cached_index(): void
    {
        $product = Product::factory()->for(Category::factory())->create(['name' => 'Original Name']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Original Name');

        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Renamed Product'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Product');

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Renamed Product');
    }

    public function test_an_inventory_adjustment_invalidates_the_cached_inventory_index(): void
    {
        $product = Product::factory()->for(Category::factory())->create(['status' => 'active']);
        InventoryItem::factory()->for($product)->create([
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
        ]);

        $this->getJson('/api/v1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_on_hand', 10);

        DB::table('inventory_items')->where('product_id', $product->id)->update(['quantity_on_hand' => 99]);

        // Still cached: an out-of-band write does not invalidate anything.
        $this->getJson('/api/v1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_on_hand', 10);

        $this->postJson("/api/v1/inventory/{$product->id}/adjust", ['type' => 'restock', 'quantity' => 1])
            ->assertOk()
            ->assertJsonPath('data.quantity_on_hand', 100);

        // The adjustment dispatches InventoryChanged, which bumps the namespace
        // version, so the next read recomputes from the database.
        $this->getJson('/api/v1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_on_hand', 100);
    }

    public function test_the_order_report_is_cached_and_invalidated_by_creating_an_order(): void
    {
        $this->getJson('/api/v1/orders/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.orders_count', 0);

        // Written straight through the model, so no OrderCreated event fires.
        Order::factory()->create(['status' => 'pending', 'total_amount' => 10000]);

        $this->getJson('/api/v1/orders/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.orders_count', 0);

        $product = Product::factory()->for(Category::factory())->create(['price' => 2500, 'status' => 'active']);
        InventoryItem::factory()->for($product)->create([
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
        ]);

        $this->withHeader('Idempotency-Key', 'report-cache-key')
            ->postJson('/api/v1/orders', [
                'customer_id' => Customer::factory()->create()->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertCreated();

        // Both orders are now visible: the API write flushed the reports cache.
        $this->getJson('/api/v1/orders/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.orders_count', 2)
            ->assertJsonPath('data.total_sales', '150.00');
    }

    public function test_different_query_parameters_do_not_collide_in_the_cache(): void
    {
        $category = Category::factory()->create();

        $this->travelTo('2026-01-01 10:00:00');
        $older = Product::factory()->for($category)->create(['name' => 'Alpha Widget']);

        $this->travelTo('2026-01-02 10:00:00');
        $newer = Product::factory()->for($category)->create(['name' => 'Beta Widget']);

        $this->travelBack();

        $this->getJson('/api/v1/products?search=Alpha')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Alpha Widget');

        $this->getJson('/api/v1/products?search=Beta')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Beta Widget');

        // Page number is part of the cache key even though it is not a
        // validated filter, so page 2 cannot be served page 1's payload.
        $this->getJson('/api/v1/products?per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id);

        $this->getJson('/api/v1/products?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id);
    }
}
