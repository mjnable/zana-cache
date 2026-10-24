<?php

declare(strict_types=1);

namespace Zana\Cache;

/**
 * Stores nothing: every read is a miss.
 */
final class NullCache extends AbstractCache
{
    public function clear(): bool
    {
        return true;
    }

    protected function fetch(string $key, int $now): array
    {
        return [false, null];
    }

    protected function store(string $key, mixed $value, ?int $expiresAt): bool
    {
        return true;
    }

    protected function remove(string $key): void
    {
    }
}
