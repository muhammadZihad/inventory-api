<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_list_returns_product_identity_and_title(): void
    {
        Passport::actingAs(User::factory()->create());

        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['name' => 'Mechanical Keyboard']);
        InventoryItem::factory()->for($product)->create(['quantity_on_hand' => 30]);

        $this->getJson('/api/v1/inventory?product_id='.$product->id)
            ->assertOk()
            ->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.product_title', 'Mechanical Keyboard')
            ->assertJsonPath('data.0.product.id', $product->id)
            ->assertJsonPath('data.0.product.title', 'Mechanical Keyboard');
    }
}
