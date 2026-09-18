<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Decides who may change the catalog.
 *
 * Reading the catalog is open to every authenticated client, because ordering
 * requires browsing it. Changing it — creating products, editing prices,
 * deleting them, or moving stock — is a back-office operation reserved for
 * administrators. Without this, any self-registered account could rewrite
 * prices or empty the catalog.
 */
class ProductPolicy
{
    /**
     * Determine whether the user may add a product to the catalog.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Determine whether the user may edit a product.
     */
    public function update(User $user, Product $product): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Determine whether the user may remove a product.
     */
    public function delete(User $user, Product $product): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Determine whether the user may adjust a product's stock balance.
     */
    public function adjustInventory(User $user, Product $product): bool
    {
        return (bool) $user->is_admin;
    }
}
