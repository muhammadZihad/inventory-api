<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Base Eloquent model that automatically fills audit actor columns.
 */
abstract class BaseModel extends Model
{
    /** @var array<string, bool> */
    private static array $columnCache = [];

    /**
     * Register model events that populate audit actor columns automatically.
     */
    protected static function booted(): void
    {
        static::creating(function (BaseModel $model): void {
            $model->injectAuditFields(isCreating: true);
        });

        static::updating(function (BaseModel $model): void {
            $model->injectAuditFields(isCreating: false);
        });
    }

    /**
     * Fill created_by and updated_by from the current authenticated user when available.
     */
    protected function injectAuditFields(bool $isCreating): void
    {
        $userId = Auth::id();

        if (! $userId) {
            return;
        }

        if ($isCreating && $this->hasAuditColumn('created_by') && ! $this->getAttribute('created_by')) {
            $this->forceFill(['created_by' => $userId]);
        }

        if ($this->hasAuditColumn('updated_by')) {
            $this->forceFill(['updated_by' => $userId]);
        }
    }

    /**
     * Check once per table whether an audit column exists before filling it.
     */
    protected function hasAuditColumn(string $column): bool
    {
        $key = $this->getConnectionName().'|'.$this->getTable().'|'.$column;

        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        try {
            return self::$columnCache[$key] = Schema::connection($this->getConnectionName())->hasColumn($this->getTable(), $column);
        } catch (Throwable) {
            return self::$columnCache[$key] = false;
        }
    }
}
