<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

#[Fillable(['order_id', 'from_status', 'to_status', 'note', 'created_by'])]
/**
 * Records each status transition made to an order.
 */
class OrderStatusHistory extends BaseModel
{
    use HasUlids;
}
