<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasFilters;
use App\QueryFilters\CategoryFilter;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'status', 'created_by', 'updated_by'])]
/**
 * Represents a product category in the catalog.
 */
class Category extends BaseModel
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasFilters, HasUlids;

    protected string $filterClass = CategoryFilter::class;

    /**
     * Get products assigned to this category.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
