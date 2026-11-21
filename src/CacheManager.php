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
 *
 * File stores sign their files with cache.key, or a key derived from
 * security.secret (2.28): see FileCache.
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
            'file' => new FileCache($this->path((string) ($settings['path'] ?? 'var/cache/data')), $ttl, key: self::fileKey($this->config)),
            'null' => new NullCache(),
            default => throw new \InvalidArgumentException(sprintf('Cache store "%s" has an unknown driver: %s.', $name, var_export($settings['driver'] ?? null, true))),
        };
    }

    /**
     * The key that signs the file stores (2.28): cache.key, else one derived
     * from security.secret, so one secret signs the cache, the queue and the
     * outbox apart; else none, and the files are not signed.
     */
    public static function fileKey(ConfigurationInterface $config): ?string
    {
        if ($config->has('cache.key')) {
            return $config->string('cache.key');
        }
        $secret = $config->has('security.secret') ? $config->string('security.secret') : '';

        return $secret === '' ? null : hash_hmac('sha256', 'zana.cache.file', $secret);
    }

    private function path(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $isAbsolute ? $path : rtrim($this->basePath, '/') . '/' . $path;
    }
}
