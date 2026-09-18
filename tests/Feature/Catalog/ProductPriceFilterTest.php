<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Proves the min_price / max_price filters narrow the product index.
 */
class ProductPriceFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Passport::actingAs(User::factory()->create());

        $category = Category::factory()->create();

        // Prices are supplied in cents and stored as dollars by the model.
        foreach ([1000, 2500, 5000, 10000] as $priceInCents) {
            Product::factory()->for($category)->create(['price' => $priceInCents]);
        }
    }

    public function test_min_price_and_max_price_together_filter_the_result_set(): void
    {
        $response = $this->getJson('/api/v1/products?min_price=20&max_price=60')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(['25.00', '50.00'], $this->pricesFrom($response->json('data')));
    }

    public function test_min_price_alone_excludes_cheaper_products(): void
    {
        $response = $this->getJson('/api/v1/products?min_price=50')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(['50.00', '100.00'], $this->pricesFrom($response->json('data')));
    }

    public function test_max_price_excludes_more_expensive_products(): void
    {
        // max_price currently has to be paired with min_price; see the
        // characterisation test below for why a bare max_price is rejected.
        $response = $this->getJson('/api/v1/products?min_price=0&max_price=25')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(['10.00', '25.00'], $this->pricesFrom($response->json('data')));
    }

    public function test_max_price_works_as_a_standalone_upper_bound(): void
    {
        // An upper bound must be usable on its own: the gte comparison against
        // min_price only applies when a lower bound was actually supplied.
        $response = $this->getJson('/api/v1/products?max_price=25')->assertOk();

        $this->assertSame(['10.00', '25.00'], $this->pricesFrom($response->json('data')));
    }

    public function test_an_inverted_price_range_is_still_rejected(): void
    {
        $this->getJson('/api/v1/products?min_price=50&max_price=25')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'errors.max_price.0',
                'The max price field must be greater than or equal to min price.',
            );
    }

    public function test_price_bounds_are_inclusive(): void
    {
        // A range whose ends land exactly on stored prices keeps both of them.
        $response = $this->getJson('/api/v1/products?min_price=25.00&max_price=50.00')->assertOk();

        $this->assertSame(['25.00', '50.00'], $this->pricesFrom($response->json('data')));
    }

    public function test_an_unfiltered_request_returns_every_product(): void
    {
        // Baseline: the filtered assertions above only mean something if the
        // catalog really does contain all four products.
        $response = $this->getJson('/api/v1/products')->assertOk();

        $this->assertSame(4, $response->json('meta.total'));
        $this->assertSame(['10.00', '25.00', '50.00', '100.00'], $this->pricesFrom($response->json('data')));
    }

    /**
     * Pull the prices out of a product index payload, ordered low to high.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array<int, string>
     */
    private function pricesFrom(array $data): array
    {
        $prices = array_column($data, 'price');

        usort($prices, fn (string $a, string $b): int => (float) $a <=> (float) $b);

        return $prices;
    }
}
