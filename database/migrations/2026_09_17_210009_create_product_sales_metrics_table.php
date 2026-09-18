<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Materialised sales aggregates, one row per product.
        //
        // Deriving these on the fly meant grouping every order_items row and
        // joining the result to the whole catalog on each request, which no
        // index can help with: sorting 120k products by a computed column is a
        // filesort over the join. Storing the aggregate turns those reads into
        // an index range scan plus primary key lookups.
        Schema::create('product_sales_metrics', function (Blueprint $table): void {
            $table->foreignUlid('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('units_sold')->default(0);
            $table->unsignedBigInteger('orders_count')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            // Recomputed in the background, so it can trail the totals briefly.
            $table->unsignedInteger('sales_rank')->nullable();
            $table->actionAt();

            // One index per sortable metric column.
            $table->index('units_sold');
            $table->index('orders_count');
            $table->index('gross_sales');
            $table->index('sales_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales_metrics');
    }
};
