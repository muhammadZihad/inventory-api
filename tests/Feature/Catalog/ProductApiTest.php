<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_product_and_receive_single_object_response(): void
    {
        Passport::actingAs(User::factory()->create());

        $category = Category::factory()->create(['name' => 'Electronics']);

        $response = $this->postJson('/api/v1/products', [
            'category_id' => $category->id,
            'name' => 'Mechanical Keyboard',
            'sku' => 'KEY-001',
            'description' => 'Compact keyboard',
            'price' => '125.00',
            'status' => 'active',
            'stock_quantity' => 20,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Product created successfully.')
            ->assertJsonPath('data.sku', 'KEY-001')
            ->assertJsonPath('data.price', '125.00')
            ->assertJsonPath('data.inventory.available_quantity', 20);

        $this->assertSame(26, strlen($response->json('data.id')));
        $this->assertDatabaseHas('products', [
            'sku' => 'KEY-001',
            'price' => '125.00',
        ]);
        $this->assertSame(12500, Product::query()->where('sku', 'KEY-001')->first()->price);
    }

    public function test_product_index_supports_reusable_filter_scope_and_paginated_response(): void
    {
        Passport::actingAs(User::factory()->create());

        $electronics = Category::factory()->create(['name' => 'Electronics']);
        $books = Category::factory()->create(['name' => 'Books']);

        Product::factory()->for($electronics)->create([
            'name' => 'Wireless Mouse',
            'sku' => 'MOU-001',
            'price' => 2500,
            'status' => 'active',
        ]);
        Product::factory()->for($books)->create([
            'name' => 'Laravel Book',
            'sku' => 'BOOK-001',
            'price' => 1800,
            'status' => 'draft',
        ]);

        $this->getJson('/api/v1/products?search=mouse&status=active&min_price=20.00&max_price=30.00&sort=-price&per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Wireless Mouse')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);
    }
}
