<?php

declare(strict_types=1);

namespace Zana\Cache;

use Closure;

/**
 * Key validation, TTL handling and the multiple/remember/pull operations,
 * shared by the built-in stores. Stores implement fetch(), store() and remove().
 */
abstract class AbstractCache implements CacheInterface
{
    private const RESERVED = '{}()/\\@:';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param int|null $defaultTtl Seconds used when a TTL of null is given; null means forever
     * @param (Closure(): int)|null $clock Current Unix time, for tests
     */
    public function __construct(
        private readonly ?int $defaultTtl = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validate($key);
        [$found, $value] = $this->fetch($key, $this->now());

        return $found ? $value : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validate($key);

        $seconds = $this->seconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            $this->remove($key);

            return true;
        }

        return $this->store($key, $value, $seconds === null ? null : $this->now() + $seconds);
    }

    public function delete(string $key): bool
    {
        $this->validate($key);
        $this->remove($key);

        return true;
    }

    public function has(string $key): bool
    {
        $this->validate($key);

        return $this->fetch($key, $this->now())[0];
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function remember(string $key, null|int|\DateInterval $ttl, callable $callback): mixed
    {
        $this->validate($key);
        [$found, $value] = $this->fetch($key, $this->now());
        if ($found) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /**
     * @return array{bool, mixed} Whether the key holds an unexpired value, and the value
     */
    abstract protected function fetch(string $key, int $now): array;

    /**
     * @param int|null $expiresAt Unix time, or null for no expiry
     */
    abstract protected function store(string $key, mixed $value, ?int $expiresAt): bool;

    abstract protected function remove(string $key): void;

    protected function now(): int
    {
        return ($this->clock)();
    }

    private function seconds(null|int|\DateInterval $ttl): ?int
    {
        return match (true) {
            $ttl === null => $this->defaultTtl,
            $ttl instanceof \DateInterval => (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp(),
            default => $ttl,
        };
    }

    private function validate(string $key): void
    {
        if ($key === '') {
            throw InvalidCacheKeyException::for($key, 'it is empty');
        }

        if (strlen($key) > 255) {
            throw InvalidCacheKeyException::for(substr($key, 0, 32) . '…', 'it is longer than 255 characters');
        }

        if (strpbrk($key, self::RESERVED) !== false) {
            throw InvalidCacheKeyException::for($key, 'it contains one of the reserved characters ' . self::RESERVED);
        }
    }
}
