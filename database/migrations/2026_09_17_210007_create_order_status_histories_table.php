<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->ulid();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status')->index();
            $table->text('note')->nullable();
            $table->actionAt();
            $table->actionBy();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
