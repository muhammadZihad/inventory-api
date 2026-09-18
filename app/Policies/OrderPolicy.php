<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Decides which orders an API client may read or act on.
 *
 * Orders are owned by the API client that created them. Administrators act on
 * behalf of the business and may reach every order. Catalog data (products,
 * categories, customers) is deliberately shared rather than owned, so it has
 * no equivalent policy.
 */
class OrderPolicy
{
    /**
     * Determine whether the user may read this order.
     */
    public function view(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    /**
     * Determine whether the user may move this order through the workflow.
     */
    public function update(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    /**
     * Determine whether the user may cancel this order.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    /**
     * Grant access to administrators and to the client that created the order.
     */
    private function owns(User $user, Order $order): bool
    {
        return $user->is_admin || $order->created_by === $user->id;
    }
}
