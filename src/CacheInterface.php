<?php

declare(strict_types=1);

namespace Zana\Cache;

/**
 * A PSR-16 cache with two conveniences.
 */
interface CacheInterface extends \Psr\SimpleCache\CacheInterface
{
    /**
     * The cached value, or the callback's result stored under the key.
     * A null stored explicitly counts as a hit, so the cached value may differ
     * in type from what the callback would return.
     *
     * @param callable(): mixed $callback
     */
    public function remember(string $key, null|int|\DateInterval $ttl, callable $callback): mixed;

    /**
     * Get the value, then delete it.
     */
    public function pull(string $key, mixed $default = null): mixed;
}
