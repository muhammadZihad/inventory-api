<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Prunable;

#[Fillable(['user_id', 'key', 'method', 'path', 'request_hash', 'state', 'response_payload', 'response_status', 'expires_at'])]
/**
 * Stores request fingerprints and responses that make writes retry-safe.
 */
class IdempotencyKey extends BaseModel
{
    use HasUlids, Prunable;

    /** A request holding this claim is still running. */
    public const STATE_PROCESSING = 'processing';

    /** The request finished and its response can be replayed. */
    public const STATE_COMPLETED = 'completed';

    /**
     * Cast stored payloads and retention timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Select expired keys for the scheduled prune, keeping the table bounded.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
