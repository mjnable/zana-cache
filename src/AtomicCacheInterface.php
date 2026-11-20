<?php

declare(strict_types=1);

namespace Zana\Cache;

/**
 * A cache that can add and count atomically (2.26): two requests at the same
 * instant cannot both find a key absent, nor both read the same count. Rate
 * limits, single-use tokens and the spam rules rely on it (S34, S35); with a
 * cache that cannot, they are approximate under concurrent requests.
 *
 * Every Zana store implements it: FileCache with a lock file per key,
 * ArrayCache within its one process, NullCache trivially (it keeps nothing).
 *
 * @api
 */
interface AtomicCacheInterface extends CacheInterface
{
    /**
     * Stores the value only when the key holds none (or an expired one).
     *
     * @return bool True when it was stored; false when the key already held a value
     */
    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;

    /**
     * Adds $by to the whole number under the key, and returns the result. An
     * absent, expired or non-integer value counts as 0; the TTL applies when
     * the number is created, and an existing number keeps its expiry.
     */
    public function increment(string $key, int $by = 1, null|int|\DateInterval $ttl = null): int;
}
