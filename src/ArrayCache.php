<?php

declare(strict_types=1);

namespace Zana\Cache;

/**
 * An in-memory cache for one process. Values are copied through serialize()
 * so later changes to a cached object do not leak into the cache.
 */
final class ArrayCache extends AbstractCache
{
    /** @var array<string, array{string, ?int}> key => [serialized value, expiry] */
    private array $entries = [];

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    protected function fetch(string $key, int $now): array
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return [false, null];
        }

        if ($entry[1] !== null && $entry[1] <= $now) {
            unset($this->entries[$key]);

            return [false, null];
        }

        return [true, unserialize($entry[0])];
    }

    protected function store(string $key, mixed $value, ?int $expiresAt): bool
    {
        $this->entries[$key] = [serialize($value), $expiresAt];

        return true;
    }

    protected function remove(string $key): void
    {
        unset($this->entries[$key]);
    }
}
