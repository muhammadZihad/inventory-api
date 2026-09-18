<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\SalesMetrics;
use App\Models\User;
use App\Support\CacheNamespace;
use App\Support\CacheRepository;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds a reviewer-scale dataset: well over a million rows of catalog,
 * customer, order, and inventory data that obey the same invariants the
 * application enforces at runtime.
 *
 * The run is split into three phases, each in its own class:
 *
 *   1. {@see CatalogSeeder}   categories, customers, products, opening stock
 *   2. {@see OrderSeeder}     orders, line items, status history, movements
 *   3. {@see InventorySeeder} the stock balances those orders leave behind
 *
 * Phase two keeps per-product stock balances in memory while it walks the
 * orders in chronological order, so inventory_items and inventory_movements
 * are a true replay of the seeded orders rather than independent noise.
 *
 * Volumes can be scaled for a quick local run:
 *
 *   SEED_SCALE=0.01 php artisan db:seed
 */
class DatabaseSeeder extends Seeder
{
    /** Distinct product categories. */
    private const CATEGORY_COUNT = 200;

    /** People who place orders. */
    private const CUSTOMER_COUNT = 80_000;

    /** Catalog size; each product gets exactly one inventory_items row. */
    private const PRODUCT_COUNT = 120_000;

    /** Orders spread across the seeded history window. */
    private const ORDER_COUNT = 150_000;

    /** Upper bound on line items per order; the mean lands near two. */
    private const MAX_ORDER_LINES = 4;

    /** How far back created_at timestamps reach. */
    private const HISTORY_DAYS = 180;

    /**
     * Rows per INSERT statement.
     *
     * Kept well under MySQL's 65,535 placeholder ceiling for the widest table
     * (inventory_movements, twelve columns) while still amortising round trips.
     */
    private const CHUNK_SIZE = 2_000;

    /** Seeds mt_rand so repeated runs produce the same dataset. */
    private const RANDOM_SEED = 20260918;

    /** Tables emptied before seeding, ordered so foreign keys stay satisfiable. */
    private const TABLES = [
        'idempotency_keys',
        'order_status_histories',
        'inventory_movements',
        'order_items',
        'orders',
        'inventory_items',
        'products',
        'customers',
        'categories',
    ];

    /**
     * Seed the application database.
     */
    public function run(): void
    {
        // Seeding writes more than a million rows; a query log would grow
        // without bound and dominate memory usage.
        DB::connection()->disableQueryLog();
        mt_srand(self::RANDOM_SEED);

        $startedAt = microtime(true);
        $scale = $this->scale();
        $output = $this->output();

        $user = $this->demoUser();
        $this->truncate();

        $now = time();
        $windowStart = $now - (self::HISTORY_DAYS * 86_400);
        $orderWindowStart = CatalogSeeder::catalogReadyAt($windowStart, $now);

        $output?->writeln(sprintf(
            '<comment>Seeding %s days of history at scale %s.</comment>',
            self::HISTORY_DAYS,
            rtrim(rtrim(number_format($scale, 4, '.', ''), '0'), '.'),
        ));

        $catalog = (new CatalogSeeder($output, $user->id, self::CHUNK_SIZE, $windowStart, $now))->seed(
            $this->scaled(self::CATEGORY_COUNT, $scale, min: 1),
            $this->scaled(self::CUSTOMER_COUNT, $scale),
            $this->scaled(self::PRODUCT_COUNT, $scale),
        );

        $balances = (new OrderSeeder($output, $user->id, self::CHUNK_SIZE, $orderWindowStart, $now))->seed(
            $catalog,
            $this->scaled(self::ORDER_COUNT, $scale),
            self::MAX_ORDER_LINES,
        );

        $written = (new InventorySeeder($output, $user->id, self::CHUNK_SIZE, $now))->seed($catalog, $balances);
        $output?->writeln(sprintf('  <info>inventory items</info>: %s rows', number_format($written)));

        unset($catalog, $balances);

        $output?->writeln('<comment>Building product sales metrics…</comment>');
        app(SalesMetrics::class)->rebuild();

        $this->invalidateCachedReads();

        $this->summarise($startedAt);
    }

    /**
     * Resolve the volume multiplier from the environment.
     *
     * Read straight from the environment rather than config because this is a
     * developer-facing switch for a single command, not application config.
     */
    private function scale(): float
    {
        $scale = (float) (env('SEED_SCALE') ?? 1.0);

        return $scale > 0 ? $scale : 1.0;
    }

    /**
     * Apply the scale multiplier to a configured volume.
     */
    private function scaled(int $count, float $scale, int $min = 10): int
    {
        return max($min, (int) round($count * $scale));
    }

    /**
     * Create or refresh the demo account every seeded order is created by.
     *
     * The account is an administrator so the seeded history is visible in the
     * console immediately; non-admin clients only see orders they created.
     */
    private function demoUser(): User
    {
        return User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => Hash::make('password'), 'is_admin' => true],
        );
    }

    /**
     * Empty the domain tables so re-running the seeder is idempotent.
     */
    private function truncate(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES as $table) {
            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Print the per-table row counts, the grand total, runtime, and peak memory.
     */
    private function summarise(float $startedAt): void
    {
        $output = $this->output();

        if (! $output instanceof OutputStyle) {
            return;
        }

        $rows = [];
        $total = 0;

        foreach (array_reverse(self::TABLES) as $table) {
            $count = DB::table($table)->count();
            $total += $count;
            $rows[] = [$table, number_format($count)];
        }

        $rows[] = ['<options=bold>TOTAL</>', '<options=bold>'.number_format($total).'</>'];

        $output->newLine();
        $output->table(['Table', 'Rows'], $rows);
        $output->writeln(sprintf(
            '  <info>runtime</info>: %.1fs    <info>peak memory</info>: %.1f MB    <info>grand total</info>: %s rows',
            microtime(true) - $startedAt,
            memory_get_peak_usage(true) / 1024 / 1024,
            number_format($total),
        ));
    }

    /**
     * Get the console output, when the seeder was invoked from a command.
     */
    private function output(): ?OutputStyle
    {
        return isset($this->command) ? $this->command->getOutput() : null;
    }

    /**
     * Drop cached reads that the seeded rows have just invalidated.
     *
     * Seeding writes through the query builder, so none of the model events or
     * domain events that normally drive cache invalidation fire. Without this
     * the console would keep serving the previous dataset from Redis until the
     * entries aged out.
     */
    private function invalidateCachedReads(): void
    {
        app(CacheRepository::class)->flush(...CacheNamespace::cases());
    }
}
