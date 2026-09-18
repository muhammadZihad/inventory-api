<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Read-through cache for expensive list and aggregate reads.
 *
 * Keys are namespaced and version-stamped: "products:v7:<hash of parameters>".
 * Invalidation increments the namespace version rather than deleting keys, so
 * a single write invalidates every cached variant of a resource (all filters,
 * sorts and pages) in one operation, with no key enumeration and no dependency
 * on cache tags. Orphaned entries expire naturally through their own TTL.
 */
class CacheRepository
{
    /** Default lifetime for cached reads, in seconds. */
    public const DEFAULT_TTL = 300;

    /**
     * Bind the underlying cache store.
     */
    public function __construct(private readonly Cache $cache) {}

    /**
     * Resolve a cached value, computing and storing it on a miss.
     *
     * @param  array<string, mixed>  $parameters  Everything that makes the read unique.
     */
    public function remember(CacheNamespace $namespace, array $parameters, Closure $callback, ?int $ttl = null): mixed
    {
        return $this->cache->remember(
            $this->key($namespace, $parameters),
            $ttl ?? self::DEFAULT_TTL,
            $callback,
        );
    }

    /**
     * Invalidate every cached entry in a namespace by bumping its version.
     */
    public function flush(CacheNamespace ...$namespaces): void
    {
        foreach ($namespaces as $namespace) {
            $versionKey = $this->versionKey($namespace);

            // add() seeds the counter when it is missing so that a cold cache
            // starts at 1 rather than silently failing to increment.
            if (! $this->cache->add($versionKey, 2, null)) {
                $this->cache->increment($versionKey);
            }
        }
    }

    /**
     * Build the fully-qualified cache key for a namespaced read.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function key(CacheNamespace $namespace, array $parameters): string
    {
        ksort($parameters);

        return sprintf(
            '%s:v%d:%s',
            $namespace->value,
            $this->version($namespace),
            hash('xxh128', (string) json_encode($parameters)),
        );
    }

    /**
     * Read the current version counter for a namespace.
     */
    public function version(CacheNamespace $namespace): int
    {
        return (int) $this->cache->get($this->versionKey($namespace), 1);
    }

    /**
     * Build the cache key holding a namespace version counter.
     */
    private function versionKey(CacheNamespace $namespace): string
    {
        return 'cache-version:'.$namespace->value;
    }
}
