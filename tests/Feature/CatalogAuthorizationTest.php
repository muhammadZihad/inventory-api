<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Catalog and stock are back-office data: readable by any authenticated client,
 * writable only by an administrator.
 *
 * Registration is public, so without these checks anyone could sign up and then
 * rewrite prices, empty the catalog or invent stock.
 */
class CatalogAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create();
        $this->product = Product::factory()->for($this->category)->create(['status' => 'active']);
        InventoryItem::factory()->for($this->product)->create(['quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        $this->customer = Customer::factory()->create();
    }

    public function test_a_non_admin_cannot_write_to_the_catalog(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->postJson('/api/v1/products', [
            'category_id' => $this->category->id,
            'name' => 'Injected',
            'sku' => 'INJECT-1',
            'price' => '1.00',
            'status' => 'active',
            'stock_quantity' => 1,
        ])->assertForbidden();

        $this->putJson('/api/v1/products/'.$this->product->id, ['price' => '0.01'])->assertForbidden();
        $this->deleteJson('/api/v1/products/'.$this->product->id)->assertForbidden();

        $this->postJson('/api/v1/categories', ['name' => 'Injected', 'status' => 'active'])->assertForbidden();
        $this->putJson('/api/v1/categories/'.$this->category->id, ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson('/api/v1/categories/'.$this->category->id)->assertForbidden();

        // Nothing was written.
        $this->assertDatabaseMissing('products', ['sku' => 'INJECT-1']);
        $this->assertDatabaseHas('products', ['id' => $this->product->id]);
        $this->assertDatabaseHas('categories', ['id' => $this->category->id, 'name' => $this->category->name]);
    }

    public function test_a_non_admin_cannot_adjust_stock(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->postJson('/api/v1/inventory/'.$this->product->id.'/adjust', [
            'type' => 'restock',
            'quantity' => 9999,
        ])->assertForbidden();

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 10,
        ]);
    }

    public function test_a_non_admin_can_still_read_the_catalog_and_place_orders(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/products/'.$this->product->id)->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/inventory')->assertOk();
        $this->getJson('/api/v1/inventory/'.$this->product->id.'/movements')->assertOk();

        // Ordering requires creating a customer, so that stays open.
        $this->postJson('/api/v1/customers', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
        ])->assertCreated();

        $this->withHeader('Idempotency-Key', 'authz-order')
            ->postJson('/api/v1/orders', [
                'customer_id' => $this->customer->id,
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ])->assertCreated();
    }

    public function test_deleting_a_customer_is_restricted_to_admins(): void
    {
        Passport::actingAs(User::factory()->create());
        $this->deleteJson('/api/v1/customers/'.$this->customer->id)->assertForbidden();

        Passport::actingAs(User::factory()->create(['is_admin' => true]));
        $this->deleteJson('/api/v1/customers/'.$this->customer->id)->assertOk();
    }

    public function test_an_admin_retains_full_catalog_access(): void
    {
        Passport::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/v1/products', [
            'category_id' => $this->category->id,
            'name' => 'Admin Product',
            'sku' => 'ADMIN-1',
            'price' => '5.00',
            'status' => 'active',
            'stock_quantity' => 3,
        ])->assertCreated();

        $this->postJson('/api/v1/inventory/'.$this->product->id.'/adjust', [
            'type' => 'restock',
            'quantity' => 5,
        ])->assertOk();
    }
}
