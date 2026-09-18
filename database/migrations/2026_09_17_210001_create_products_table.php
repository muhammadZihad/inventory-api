<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->ulid();
            $table->foreignUlid('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->string('status')->default('active')->index();
            $table->actionAt();
            $table->actionBy();

            // Covers the default list query: filter by status, sort by newest.
            $table->index(['category_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
            $table->index('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
