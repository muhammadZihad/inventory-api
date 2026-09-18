<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\OrderReports;
use App\Contracts\SalesMetrics;
use App\Contracts\StockLedger;
use App\Database\Blueprint;
use App\Services\InventoryLedger;
use App\Services\OrderReportService;
use App\Services\SalesMetricsService;
use App\Support\CacheRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Registers application services, schema helpers, and rate limiters.
 *
 * Event listeners are not registered here: Laravel discovers them from the
 * type-hint on each listener's handle() method, so a listener is wired to
 * every event in its union type by declaring it. Registering them again here
 * would fire each listener twice per event.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register the schema blueprint resolver and the shared cache repository.
     */
    public function register(): void
    {
        // Laravel's schema builder resolves Blueprint through the container when
        // no resolver is set, so binding the implementation is enough to give
        // every migration ulid(), actionAt() and actionBy(). Rebinding
        // 'db.schema' instead would pin the builder to the default connection.
        $this->app->bind(BaseBlueprint::class, Blueprint::class);

        $this->app->singleton(CacheRepository::class, fn (): CacheRepository => new CacheRepository(Cache::store()));

        // Consumers type-hint the contract, not the implementation.
        $this->app->bind(StockLedger::class, InventoryLedger::class);
        $this->app->bind(SalesMetrics::class, SalesMetricsService::class);
        $this->app->bind(OrderReports::class, OrderReportService::class);
    }

    /**
     * Register schema macros, ULID morph keys, listeners, and rate limiters.
     */
    public function boot(): void
    {
        // Surfaces accidental lazy loading in development instead of letting an
        // N+1 reach production unnoticed.
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->registerRateLimiters();

        Schema::defaultMorphKeyType('ulid');
    }

    /**
     * Define the named rate limiters applied by the route files.
     *
     * Authenticated clients are limited per user so one noisy client cannot
     * consume another's budget; unauthenticated traffic falls back to IP.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Order writes take inventory row locks, so they are deliberately
        // tighter than plain reads.
        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));

        // Credential endpoints are keyed by IP because there is no user yet.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
