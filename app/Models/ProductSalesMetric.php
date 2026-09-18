<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'units_sold', 'orders_count', 'gross_sales', 'sales_rank'])]
/**
 * Materialised sales totals for one product.
 */
class ProductSalesMetric extends Model
{
    protected $table = 'product_sales_metrics';

    protected $primaryKey = 'product_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Cast the stored aggregates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units_sold' => 'integer',
            'orders_count' => 'integer',
            'sales_rank' => 'integer',
        ];
    }

    /**
     * Get the product these metrics belong to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
