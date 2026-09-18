<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('order_number')->unique();
            $table->string('status')->default('pending')->index();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->actionAt('cancelled');
            $table->actionBy('cancelled');
            $table->actionAt();
            $table->actionBy();

            $table->index(['customer_id', 'status']);
            $table->index(['created_at', 'status']);
            $table->index('total_amount');
            // Covering index for the customer order aggregate.
            $table->index(['customer_id', 'status', 'total_amount'], 'orders_customer_metrics_index');
            // Orders are scoped to their creator for non-admin API clients.
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
