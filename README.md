# Zana Cache

PSR-16 caches with `remember()` and `pull()`, and named stores from
configuration. Requires PHP 8.5 or later.

```
composer require mj-zana/cache
```

## Usage

Register `CacheServiceProvider` and configure stores in `config/cache.php`:

```php
return [
    'default' => 'file',
    'stores' => [
        'file' => ['driver' => 'file', 'path' => 'var/cache/data', 'ttl' => 3600],
        'memory' => ['driver' => 'array'],
    ],
];
```

```php
public function __construct(private CacheInterface $cache) {}

$report = $this->cache->remember("bmi.report.{$patientId}", 600, fn () => $this->builder->build($patientId));
$this->cache->delete("bmi.report.{$patientId}");
```

## Stores

| Store | Behaviour |
|---|---|
| `array` | in memory, per process; values are copied through `serialize()` |
| `file` | one file per key, written atomically; corrupt or expired files are misses |
| `null` | stores nothing |

- **Keys** follow PSR-16: up to 255 characters, without `{}()/\@:`.
- **TTL** is seconds or a `DateInterval`. `null` uses the store's default
  (forever unless configured), and a TTL ≤ 0 deletes the entry.
- **Defaults:** without configuration, the default store is `array`.
- **Atomic (2.26):** `add($key, $value, $ttl)` stores only when the key holds nothing and says whether it did;
  `increment($key, $by, $ttl)` returns the new count, and an existing count keeps its expiry. Requests at the same
  instant take turns: the file store locks the key while it reads and writes. Every store implements
  `AtomicCacheInterface`; mj-zana/security's rate limits and single-use tokens rely on it.

## License

MIT — see [LICENSE](LICENSE).
