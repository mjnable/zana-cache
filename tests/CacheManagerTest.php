<?php

declare(strict_types=1);

namespace Zana\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Cache\ArrayCache;
use Zana\Cache\CacheInterface;
use Zana\Cache\CacheManager;
use Zana\Cache\CacheServiceProvider;
use Zana\Cache\FileCache;
use Zana\Cache\Inspection\FileSigningCheck;
use Zana\Cache\NullCache;
use Zana\Configuration\Configuration;
use Zana\Core\Application;

final class CacheManagerTest extends TestCase
{
    public function testStoresComeFromConfiguration(): void
    {
        $manager = new CacheManager(new Configuration(['cache' => [
            'default' => 'files',
            'stores' => [
                'files' => ['driver' => 'file', 'path' => 'var/cache', 'ttl' => 60],
                'memory' => ['driver' => 'array'],
                'off' => ['driver' => 'null'],
            ],
        ]]), sys_get_temp_dir());

        $this->assertInstanceOf(FileCache::class, $manager->store());
        $this->assertSame($manager->store(), $manager->store('files'), 'stores are cached');
        $this->assertInstanceOf(ArrayCache::class, $manager->store('memory'));
        $this->assertInstanceOf(NullCache::class, $manager->store('off'));
    }

    public function testTheDefaultIsAnArrayStore(): void
    {
        $this->assertInstanceOf(ArrayCache::class, new CacheManager(new Configuration(), '/app')->store());
    }

    public function testUnknownStoresAndDriversAreRejected(): void
    {
        $manager = new CacheManager(new Configuration(['cache' => ['stores' => ['redis' => ['driver' => 'redis']]]]), '/app');

        foreach (['missing' => 'Cache store "missing" is not configured.', 'redis' => "unknown driver: 'redis'"] as $store => $message) {
            try {
                $manager->store($store);
                $this->fail("Expected: {$message}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testTheNullCacheStoresNothing(): void
    {
        $cache = new NullCache();
        $cache->set('a', 1);

        $this->assertFalse($cache->has('a'));
        $this->assertSame(2, $cache->remember('a', null, static fn (): int => 2));
        $this->assertTrue($cache->clear());
        $this->assertTrue($cache->delete('a'));
    }

    /**
     * 2.28: the file stores sign with cache.key, else a key derived from security.secret, never the secret itself.
     */
    public function testFileStoresAreSignedWithTheCacheKeyOrOneDerivedFromTheSecret(): void
    {
        $secret = str_repeat('s', 40);
        $files = ['default' => 'files', 'stores' => ['files' => ['driver' => 'file', 'path' => 'var/cache']]];

        $this->assertNull(CacheManager::fileKey(new Configuration(['cache' => $files])));
        $this->assertFalse(self::fileStore(['cache' => $files])->signs(), 'no key: unsigned, as before');

        $derived = CacheManager::fileKey(new Configuration(['cache' => $files, 'security' => ['secret' => $secret]]));
        $this->assertSame(hash_hmac('sha256', 'zana.cache.file', $secret), $derived);
        $this->assertNotSame($secret, $derived);
        $this->assertTrue(self::fileStore(['cache' => $files, 'security' => ['secret' => $secret]])->signs());

        $own = str_repeat('c', 32);
        $this->assertSame($own, CacheManager::fileKey(new Configuration(['cache' => [...$files, 'key' => $own], 'security' => ['secret' => $secret]])), 'cache.key comes first');
        $this->assertNull(CacheManager::fileKey(new Configuration(['security' => ['secret' => '']])), 'an empty secret signs nothing');
    }

    public function testCheckWarnsWhileAFileStoreIsNotSigned(): void
    {
        $files = ['default' => 'files', 'stores' => ['files' => ['driver' => 'file', 'path' => 'var/cache'], 'memory' => ['driver' => 'array']]];

        $unsigned = self::findings(['cache' => $files]);
        $this->assertSame([['cache.file_signing', 'warning']], $unsigned['ids']);
        $this->assertStringContainsString('The file cache (files) is not signed', $unsigned['messages'][0]);

        $this->assertSame([], self::findings(['cache' => $files, 'security' => ['secret' => str_repeat('s', 40)]])['ids']);
        $this->assertSame([['cache.file_signing', 'error']], self::findings(['cache' => [...$files, 'key' => 'short']])['ids']);
        $this->assertSame([], self::findings(['cache' => ['default' => 'memory', 'stores' => ['memory' => ['driver' => 'array']]]])['ids'], 'no file store, nothing to sign');
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function fileStore(array $config): FileCache
    {
        $store = (new CacheManager(new Configuration($config), sys_get_temp_dir()))->store();
        assert($store instanceof FileCache);

        return $store;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{ids: list<array{0: string, 1: string}>, messages: list<string>}
     */
    private static function findings(array $config): array
    {
        $app = Application::configure(sys_get_temp_dir() . '/zana-cache-app')->withProviders(CacheServiceProvider::class)->withConfiguration($config)->build();
        $check = $app->container()->get(FileSigningCheck::class);
        assert($check instanceof FileSigningCheck);
        $findings = $check->findings($app);

        return [
            'ids' => array_map(static fn ($f): array => [$f->id, $f->severity->value], $findings),
            'messages' => array_map(static fn ($f): string => $f->message, $findings),
        ];
    }

    public function testTheProviderRegistersTheDefaultStore(): void
    {
        $container = Application::configure(sys_get_temp_dir() . '/zana-cache-app')->withProviders(CacheServiceProvider::class)->build()->container();

        $cache = $container->get(CacheInterface::class);
        $this->assertSame($cache, $container->get(\Psr\SimpleCache\CacheInterface::class));
        $this->assertSame($cache, $container->get(CacheManager::class)->store());
    }
}
