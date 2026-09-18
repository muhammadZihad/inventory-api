<?php

declare(strict_types=1);

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

// Idempotency keys are only replayable for a bounded window, so expired rows
// are pruned nightly to keep the table from growing without limit.
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
