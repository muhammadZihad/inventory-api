<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->ulid();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('method');
            $table->string('path');
            $table->char('request_hash', 64);
            // processing = claimed by an in-flight request, completed = replayable.
            $table->string('state')->default('processing');
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->actionAt();
            $table->actionBy();

            // The arbiter of concurrent duplicate requests.
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
