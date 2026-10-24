<?php

declare(strict_types=1);

namespace Zana\Cache;

use Zana\Configuration\ConfigurationInterface;

/**
 * Builds and caches named stores from the "cache" configuration.
 *
 *     'cache' => [
 *         'default' => 'file',
 *         'stores' => [
 *             'file' => ['driver' => 'file', 'path' => 'var/cache/data', 'ttl' => 3600],
 *             'request' => ['driver' => 'array'],
 *         ],
 *     ]
 */
final class CacheManager
{
    /** @var array<string, CacheInterface> */
    private array $stores = [];

    public function __construct(
        private readonly ConfigurationInterface $config,
        private readonly string $basePath,
    ) {
    }

    /**
     * @throws \InvalidArgumentException For an unknown store or driver
     */
    public function store(?string $name = null): CacheInterface
    {
        $name ??= $this->config->string('cache.default', 'array');

        return $this->stores[$name] ??= $this->create($name);
    }

    private function create(string $name): CacheInterface
    {
        $settings = $this->config->array("cache.stores.{$name}", []);

        if ($settings === []) {
            if ($name === 'array' && !$this->config->has('cache.stores')) {
                return new ArrayCache();
            }

            throw new \InvalidArgumentException("Cache store \"{$name}\" is not configured.");
        }

        $ttl = isset($settings['ttl']) && is_int($settings['ttl']) ? $settings['ttl'] : null;

        return match ($settings['driver'] ?? null) {
            'array' => new ArrayCache($ttl),
            'file' => new FileCache($this->path((string) ($settings['path'] ?? 'var/cache/data')), $ttl),
            'null' => new NullCache(),
            default => throw new \InvalidArgumentException(sprintf('Cache store "%s" has an unknown driver: %s.', $name, var_export($settings['driver'] ?? null, true))),
        };
    }

    private function path(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $isAbsolute ? $path : rtrim($this->basePath, '/') . '/' . $path;
    }
}
