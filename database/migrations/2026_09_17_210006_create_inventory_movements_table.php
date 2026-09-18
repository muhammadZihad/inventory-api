<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->ulid();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            // Signed change applied to on-hand stock, and the balance after it.
            $table->integer('quantity_delta');
            $table->unsignedInteger('quantity_after');
            // Signed change applied to reserved stock, and the balance after it.
            // Reservations move stock between available and reserved without
            // changing the physical on-hand count, so both are recorded.
            $table->integer('reserved_delta')->default(0);
            $table->unsignedInteger('reserved_after')->default(0);
            $table->actionAt();
            $table->actionBy();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
