<?php

declare(strict_types=1);

namespace Zana\Cache;

use Closure;

/**
 * One file per key: the key's SHA-256 under a two-character directory, holding
 * the expiry and the serialized value. Writes are atomic; expired or corrupt
 * files count as misses and are removed.
 */
final class FileCache extends AbstractCache
{
    private readonly string $directory;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(string $directory, ?int $defaultTtl = null, ?Closure $clock = null)
    {
        parent::__construct($defaultTtl, $clock);

        $this->directory = rtrim(str_replace('\\', '/', $directory), '/');
    }

    /**
     * Remove this cache's files only: other files in the directory are kept.
     */
    public function clear(): bool
    {
        foreach (glob($this->directory . '/[0-9a-f][0-9a-f]', GLOB_ONLYDIR) ?: [] as $bucket) {
            foreach (glob($bucket . '/*') ?: [] as $file) {
                if (preg_match('/^[0-9a-f]{64}$/', basename($file)) === 1) {
                    @unlink($file);
                }
            }
            @rmdir($bucket);
        }

        return true;
    }

    protected function fetch(string $key, int $now): array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return [false, null];
        }

        $contents = @file_get_contents($path);
        $entry = $contents === false ? false : @unserialize($contents);

        if (!is_array($entry) || !array_key_exists(0, $entry) || !array_key_exists(1, $entry) || !(is_int($entry[0]) || $entry[0] === null)) {
            @unlink($path);

            return [false, null];
        }

        if ($entry[0] !== null && $entry[0] <= $now) {
            @unlink($path);

            return [false, null];
        }

        return [true, $entry[1]];
    }

    protected function store(string $key, mixed $value, ?int $expiresAt): bool
    {
        $path = $this->path($key);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, serialize([$expiresAt, $value])) === false) {
            return false;
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    protected function remove(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $key);

        return "{$this->directory}/{$hash[0]}{$hash[1]}/{$hash}";
    }
}
