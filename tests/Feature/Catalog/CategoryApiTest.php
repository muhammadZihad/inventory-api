<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_list_and_show_include_products_count(): void
    {
        Passport::actingAs(User::factory()->create());

        $category = Category::factory()->create(['name' => 'Audio']);
        Product::factory()->count(3)->for($category)->create();

        $this->getJson('/api/v1/categories?search=audio')
            ->assertOk()
            ->assertJsonPath('data.0.products_count', 3);

        $this->getJson('/api/v1/categories/'.$category->id)
            ->assertOk()
            ->assertJsonPath('data.products_count', 3);
    }
}
