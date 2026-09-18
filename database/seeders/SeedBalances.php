<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Final stock balances produced by replaying the seeded orders.
 *
 * Both lists are keyed by product index, matching {@see SeedCatalog}.
 */
final class SeedBalances
{
    /**
     * @param  list<int>  $onHand  Opening stock minus units shipped on completed orders.
     * @param  list<int>  $reserved  Units held by pending and confirmed orders.
     */
    public function __construct(
        public readonly array $onHand,
        public readonly array $reserved,
    ) {}
}
