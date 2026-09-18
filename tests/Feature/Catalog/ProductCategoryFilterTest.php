<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProductCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_index_can_filter_by_category_name_or_slug(): void
    {
        Passport::actingAs(User::factory()->create());

        $electronics = Category::factory()->create(['name' => 'Electronics', 'slug' => 'electronics']);
        $books = Category::factory()->create(['name' => 'Books', 'slug' => 'books']);

        Product::factory()->for($electronics)->create(['name' => 'Bluetooth Speaker']);
        Product::factory()->for($books)->create(['name' => 'Laravel Book']);

        $this->getJson('/api/v1/products?category=electro&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Bluetooth Speaker')
            ->assertJsonPath('data.0.category_name', 'Electronics');
    }
}
