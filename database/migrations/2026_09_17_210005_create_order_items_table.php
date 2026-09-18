<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->ulid();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->actionAt();
            $table->actionBy();

            $table->index(['product_id', 'created_at']);
            // Covering index for the product sales aggregate: every column the
            // GROUP BY reads is in the index, so it never touches the table.
            $table->index(['product_id', 'order_id', 'quantity', 'line_total'], 'order_items_sales_metrics_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
