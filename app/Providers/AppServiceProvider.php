<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\Blueprint;
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
        $this->app->bind('db.schema', function ($app) {
            $builder = $app['db']->connection()->getSchemaBuilder();
            $builder->blueprintResolver(fn ($connection, $table, $callback) => new Blueprint($connection, $table, $callback));

            return $builder;
        });

        $this->app->singleton(CacheRepository::class, fn (): CacheRepository => new CacheRepository(Cache::store()));
    }

    /**
     * Register schema macros, ULID morph keys, listeners, and rate limiters.
     */
    public function boot(): void
    {
        // Surfaces accidental lazy loading in development instead of letting an
        // N+1 reach production unnoticed.
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->registerSchemaMacros();
        $this->registerRateLimiters();

        Schema::defaultMorphKeyType('ulid');
    }

    /**
     * Register the shared column macros used across migrations.
     */
    private function registerSchemaMacros(): void
    {
        BaseBlueprint::macro('actionAt', function (?string $action = null) {
            if ($action) {
                return $this->dateTime($action.'_at')->nullable();
            }

            $this->dateTime('created_at')->nullable();
            $this->dateTime('updated_at')->nullable();
        });

        BaseBlueprint::macro('actionBy', function (?string $action = null) {
            if ($action) {
                return $this->char($action.'_by', 26)->nullable()->index();
            }

            $this->char('created_by', 26)->nullable()->index();
            $this->char('updated_by', 26)->nullable()->index();
        });
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
