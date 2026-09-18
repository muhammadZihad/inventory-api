<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Decides who may maintain customer records.
 *
 * Any authenticated client may create and correct a customer, because placing
 * an order requires one. Deletion is destructive and cascades to that
 * customer's orders, so it is limited to administrators.
 */
class CustomerPolicy
{
    /**
     * Determine whether the user may register a customer.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user may correct a customer's details.
     */
    public function update(User $user, Customer $customer): bool
    {
        return true;
    }

    /**
     * Determine whether the user may delete a customer and their orders.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return (bool) $user->is_admin;
    }
}
