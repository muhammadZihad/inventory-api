<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => fake()->words(2, true),
            'sku' => Str::upper(Str::random(3)).'-'.fake()->unique()->numberBetween(100, 999),
            'description' => fake()->sentence(),
            'price' => fake()->numberBetween(500, 50000),
            'status' => 'active',
        ];
    }
}
