<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * The catalog rows already written, indexed so orders can reference them.
 *
 * Every list is keyed by the same product (or customer) index, which lets the
 * order seeder keep running stock balances in plain integer arrays instead of
 * reading rows back out of the database.
 */
final class SeedCatalog
{
    /**
     * @param  list<string>  $customerIds  Customer ULIDs, ordered by creation time.
     * @param  list<int>  $customerCreatedAt  Customer creation timestamps, ascending.
     * @param  list<string>  $productIds  Product ULIDs, ordered by creation time.
     * @param  list<int>  $productPriceCents  Product prices in integer cents.
     * @param  list<int>  $productCreatedAt  Product creation timestamps, ascending.
     * @param  list<int>  $openingStock  Units received before any order was placed.
     */
    public function __construct(
        public readonly array $customerIds,
        public readonly array $customerCreatedAt,
        public readonly array $productIds,
        public readonly array $productPriceCents,
        public readonly array $productCreatedAt,
        public readonly array $openingStock,
    ) {}
}
