<?php

declare(strict_types=1);

namespace Zana\Cache;

use Closure;

/**
 * One file per key: the key's SHA-256 under a two-character directory, holding
 * the expiry and the serialized value. Writes are atomic; expired or corrupt
 * files count as misses and are removed.
 *
 * add() and increment() (2.26) hold an exclusive lock on the key's ".lock"
 * file between their read and their write, so processes at the same instant
 * take turns (S34, S35).
 *
 * With a key (2.28), every file is signed, and a file is unserialized only
 * once its signature is checked: unserialize() builds objects, so a file
 * written by anything but the application could run code. The signature
 * covers the cache key too, so a file copied to another key's place is
 * refused. A file that is unsigned, or signed wrongly, is a miss and is
 * removed, so a cache written before 2.28 simply fills again. Without a key,
 * files are neither signed nor checked, as before.
 */
final class FileCache extends AbstractCache
{
    public const int MINIMUM_KEY_LENGTH = 32;

    private const string PREFIX = 'zc1.';

    private readonly string $directory;

    private readonly ?string $key;

    /**
     * @param (Closure(): int)|null $clock
     * @param string|null $key Signs the files (2.28): at least 32 characters
     */
    public function __construct(string $directory, ?int $defaultTtl = null, ?Closure $clock = null, #[\SensitiveParameter] ?string $key = null)
    {
        parent::__construct($defaultTtl, $clock);

        if ($key !== null && strlen($key) < self::MINIMUM_KEY_LENGTH) {
            throw new \InvalidArgumentException('The file cache key must be at least ' . self::MINIMUM_KEY_LENGTH . ' characters; generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }
        $this->directory = rtrim(str_replace('\\', '/', $directory), '/');
        $this->key = $key;
    }

    /**
     * Whether the files are signed.
     */
    public function signs(): bool
    {
        return $this->key !== null;
    }

    /**
     * Remove this cache's files only: other files in the directory are kept.
     */
    public function clear(): bool
    {
        foreach (glob($this->directory . '/[0-9a-f][0-9a-f]', GLOB_ONLYDIR) ?: [] as $bucket) {
            foreach (glob($bucket . '/*') ?: [] as $file) {
                if (preg_match('/^[0-9a-f]{64}(\.lock)?$/', basename($file)) === 1) {
                    @unlink($file);
                }
            }
            @rmdir($bucket);
        }

        return true;
    }

    protected function atomically(string $key, Closure $operation): mixed
    {
        $path = $this->path($key);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("The cache directory {$directory} cannot be created, so the key cannot be locked.");
        }
        $lock = @fopen("{$path}.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            // Never run unlocked: a rate limit or a single-use token would silently stop holding.
            throw new \RuntimeException("The cache cannot lock {$path}.lock, so the operation cannot be made atomic.");
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function fetchEntry(string $key, int $now): array
    {
        $entry = $this->read($key, $now);

        return $entry === null ? [false, null, null] : [true, $entry[1], $entry[0]];
    }

    protected function fetch(string $key, int $now): array
    {
        $entry = $this->read($key, $now);

        return $entry === null ? [false, null] : [true, $entry[1]];
    }

    /**
     * The entry [expiry, value], or null for a miss; a file that is corrupt, expired or not signed as it should be
     * is removed.
     *
     * @return array{0: int|null, 1: mixed}|null
     */
    private function read(string $key, int $now): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        $serialized = $contents === false ? null : $this->open($key, $contents);
        // Only a file this application signed is unserialized (2.28): building its objects may run their code.
        $entry = $serialized === null ? false : @unserialize($serialized);

        if (!is_array($entry) || !array_key_exists(0, $entry) || !array_key_exists(1, $entry) || !(is_int($entry[0]) || $entry[0] === null)) {
            @unlink($path);

            return null;
        }

        if ($entry[0] !== null && $entry[0] <= $now) {
            @unlink($path);

            return null;
        }

        return [$entry[0], $entry[1]];
    }

    /**
     * The serialized entry inside a file, once its signature is checked; null when it is not signed as it should be.
     */
    private function open(string $key, string $contents): ?string
    {
        $signed = str_starts_with($contents, self::PREFIX);
        if ($this->key === null) {
            return $signed ? null : $contents;   // signed with a key this cache no longer has: a miss
        }
        if (!$signed) {
            return null;
        }
        $parts = explode('.', substr($contents, strlen(self::PREFIX)), 2);

        return count($parts) === 2 && hash_equals($this->mac($key, $parts[1]), $parts[0]) ? $parts[1] : null;
    }

    private function seal(string $key, string $serialized): string
    {
        return $this->key === null ? $serialized : self::PREFIX . $this->mac($key, $serialized) . '.' . $serialized;
    }

    private function mac(string $key, string $serialized): string
    {
        return hash_hmac('sha256', $key . "\0" . $serialized, (string) $this->key);
    }

    protected function store(string $key, mixed $value, ?int $expiresAt): bool
    {
        $path = $this->path($key);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $this->seal($key, serialize([$expiresAt, $value]))) === false) {
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
