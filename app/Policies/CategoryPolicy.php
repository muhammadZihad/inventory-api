<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Decides who may change the category tree.
 *
 * Categories are shared catalog structure, so they follow the same rule as
 * products: readable by any authenticated client, writable only by an
 * administrator.
 */
class CategoryPolicy
{
    /**
     * Determine whether the user may create a category.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Determine whether the user may edit a category.
     */
    public function update(User $user, Category $category): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Determine whether the user may remove a category.
     */
    public function delete(User $user, Category $category): bool
    {
        return (bool) $user->is_admin;
    }
}
