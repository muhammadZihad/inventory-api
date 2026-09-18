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

/**
 * Covers manual stock adjustments and the movement ledger they write.
 */
class InventoryAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Passport::actingAs(User::factory()->create());
    }

    public function test_a_positive_restock_increases_on_hand_and_writes_a_restock_movement(): void
    {
        $product = $this->productWithStock(50, 5);

        $this->postJson("/api/v1/inventory/{$product->id}/adjust", [
            'type' => 'restock',
            'quantity' => 25,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Inventory adjusted successfully.')
            ->assertJsonPath('data.quantity_on_hand', 75)
            ->assertJsonPath('data.quantity_reserved', 5)
            // available = on hand (75) minus the units already reserved (5).
            ->assertJsonPath('data.available_quantity', 70);

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $product->id,
            'quantity_on_hand' => 75,
            'quantity_reserved' => 5,
        ]);

        // quantity_after is the balance *after* the movement, so the ledger can
        // be replayed to reconstruct the current balance.
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => 'restock',
            'quantity_delta' => 25,
            'quantity_after' => 75,
            'reserved_delta' => 0,
            'reserved_after' => 5,
        ]);
    }

    public function test_a_negative_correction_decreases_on_hand_and_writes_a_correction_movement(): void
    {
        $product = $this->productWithStock(50, 0);

        $this->postJson("/api/v1/inventory/{$product->id}/adjust", [
            'type' => 'correction',
            'quantity' => -10,
        ])
            ->assertOk()
            ->assertJsonPath('data.quantity_on_hand', 40)
            ->assertJsonPath('data.available_quantity', 40);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => 'correction',
            'quantity_delta' => -10,
            'quantity_after' => 40,
            'reserved_delta' => 0,
            'reserved_after' => 0,
        ]);
    }

    public function test_a_negative_restock_is_rejected(): void
    {
        $product = $this->productWithStock(50, 0);

        $this->postJson("/api/v1/inventory/{$product->id}/adjust", [
            'type' => 'restock',
            'quantity' => -5,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'errors.quantity.0',
                'A restock must add stock, so the quantity must be positive. Use a correction to remove stock.',
            );

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $product->id,
            'quantity_on_hand' => 50,
        ]);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_a_zero_quantity_adjustment_is_rejected(): void
    {
        $product = $this->productWithStock(50, 0);

        $this->postJson("/api/v1/inventory/{$product->id}/adjust", [
            'type' => 'correction',
            'quantity' => 0,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('quantity');

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_a_correction_that_would_uncover_reserved_stock_is_rejected_and_leaves_the_balance_unchanged(): void
    {
        $product = $this->productWithStock(10, 8);

        $this->postJson("/api/v1/inventory/{$product->id}/adjust", [
            'type' => 'correction',
            'quantity' => -5,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'errors.quantity.0',
                'This adjustment would leave 5 units on hand while 8 are reserved for open orders.',
            );

        // The whole adjustment runs inside one transaction, so a rejection
        // leaves neither the balance nor the ledger changed.
        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 8,
        ]);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_movements_are_returned_newest_first_and_can_be_filtered_by_type(): void
    {
        $product = $this->productWithStock(50, 0);

        $this->adjust($product->id, 'restock', 5);
        $this->travel(2)->seconds();
        $this->adjust($product->id, 'correction', -3);
        $this->travel(2)->seconds();
        $this->adjust($product->id, 'restock', 7);

        $this->getJson("/api/v1/inventory/{$product->id}/movements")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            // Newest first: the last adjustment applied is the first row back.
            ->assertJsonPath('data.0.type', 'restock')
            ->assertJsonPath('data.0.quantity_delta', 7)
            ->assertJsonPath('data.0.quantity_after', 59)
            ->assertJsonPath('data.1.type', 'correction')
            ->assertJsonPath('data.1.quantity_delta', -3)
            ->assertJsonPath('data.1.quantity_after', 52)
            ->assertJsonPath('data.2.type', 'restock')
            ->assertJsonPath('data.2.quantity_delta', 5)
            ->assertJsonPath('data.2.quantity_after', 55);

        $this->getJson("/api/v1/inventory/{$product->id}/movements?type=correction")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'correction');
    }

    /**
     * Create a product with an opening inventory balance.
     */
    private function productWithStock(int $onHand, int $reserved): Product
    {
        $product = Product::factory()->for(Category::factory())->create(['status' => 'active']);

        InventoryItem::factory()->for($product)->create([
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved,
        ]);

        return $product;
    }

    /**
     * Apply an adjustment through the API and assert it succeeded.
     */
    private function adjust(string $productId, string $type, int $quantity): void
    {
        $this->postJson("/api/v1/inventory/{$productId}/adjust", [
            'type' => $type,
            'quantity' => $quantity,
        ])->assertOk();
    }
}
