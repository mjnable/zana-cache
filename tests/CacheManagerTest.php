<?php

declare(strict_types=1);

namespace Zana\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Cache\ArrayCache;
use Zana\Cache\CacheInterface;
use Zana\Cache\CacheManager;
use Zana\Cache\CacheServiceProvider;
use Zana\Cache\FileCache;
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

    public function testTheProviderRegistersTheDefaultStore(): void
    {
        $container = Application::configure(sys_get_temp_dir() . '/zana-cache-app')->withProviders(CacheServiceProvider::class)->build()->container();

        $cache = $container->get(CacheInterface::class);
        $this->assertSame($cache, $container->get(\Psr\SimpleCache\CacheInterface::class));
        $this->assertSame($cache, $container->get(CacheManager::class)->store());
    }
}
