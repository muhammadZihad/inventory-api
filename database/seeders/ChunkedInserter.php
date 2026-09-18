<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;

/**
 * Buffers rows and flushes them to a table in fixed-size bulk inserts.
 *
 * Seeding writes well over a million rows, so rows are handed over one at a
 * time and written in chunks instead of being accumulated in a single array.
 * Peak memory therefore stays proportional to the chunk size, not the table.
 */
final class ChunkedInserter
{
    /**
     * Rows waiting to be written.
     *
     * @var list<array<string, mixed>>
     */
    private array $buffer = [];

    private int $written = 0;

    /**
     * @param  string  $table  Table the buffered rows belong to.
     * @param  int  $chunkSize  Rows per INSERT statement.
     */
    public function __construct(
        private readonly string $table,
        private readonly int $chunkSize,
    ) {}

    /**
     * Queue a row for the next flush.
     *
     * Buffers deliberately do not flush themselves: tables that reference one
     * another have to be written parent-first, so the caller decides when a
     * full chunk is written by checking {@see isFull()}.
     *
     * @param  array<string, mixed>  $row
     */
    public function add(array $row): void
    {
        $this->buffer[] = $row;
    }

    /**
     * Determine whether a full chunk is waiting to be written.
     */
    public function isFull(): bool
    {
        return count($this->buffer) >= $this->chunkSize;
    }

    /**
     * Write any buffered rows immediately.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        DB::table($this->table)->insert($this->buffer);

        $this->written += count($this->buffer);
        $this->buffer = [];
    }

    /**
     * Get the number of rows written so far.
     */
    public function written(): int
    {
        return $this->written + count($this->buffer);
    }
}
