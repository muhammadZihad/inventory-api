<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Data\Catalog\ProductData;
use App\Events\Catalog\CatalogChanged;
use App\Events\Inventory\InventoryChanged;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Creates catalog products together with their opening inventory row.
 */
class CreateProductAction
{
    /**
     * Persist a product and its inventory balance in one transaction.
     */
    public function execute(ProductData $data): Product
    {
        $product = DB::transaction(function () use ($data): Product {
            $product = Product::query()->create($data->toProductAttributes());

            $product->inventory()->create([
                'quantity_on_hand' => $data->stockQuantity ?? 0,
                'quantity_reserved' => 0,
            ]);

            return $product;
        });

        // Cache invalidation is driven by events rather than inline calls, so
        // every write path invalidates consistently through one listener.
        CatalogChanged::dispatch('product', $product->id);
        InventoryChanged::dispatch([$product->id]);

        return $product->load(['category', 'inventory']);
    }
}
