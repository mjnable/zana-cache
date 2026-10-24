<?php

declare(strict_types=1);

namespace Zana\Cache\Tests;

use PHPUnit\Framework\Attributes\After;
use Zana\Cache\CacheInterface;
use Zana\Cache\FileCache;

final class FileCacheTest extends CacheContractTestCase
{
    private ?string $directory = null;

    #[After]
    public function removeDirectory(): void
    {
        if ($this->directory === null || !is_dir($this->directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    public function testFilesAreSharedBetweenInstances(): void
    {
        $this->cache()->set('shared', 'value');

        $this->assertSame('value', $this->cache()->get('shared'));
    }

    public function testCorruptFilesAreMissesAndRemoved(): void
    {
        $cache = $this->cache();
        $cache->set('corrupt', 'value');

        $file = $this->fileFor('corrupt');
        file_put_contents($file, 'not serialized');

        $this->assertFalse($cache->has('corrupt'));
        $this->assertFileDoesNotExist($file);
    }

    public function testExpiredFilesAreRemoved(): void
    {
        $cache = $this->cache();
        $cache->set('short', 'value', 1);
        $this->now += 2;

        $this->assertNull($cache->get('short'));
        $this->assertFileDoesNotExist($this->fileFor('short'));
    }

    public function testClearKeepsForeignFiles(): void
    {
        $cache = $this->cache();
        $cache->set('mine', 'value');
        file_put_contents("{$this->directory}/keep.txt", 'not a cache file');

        $cache->clear();

        $this->assertFileExists("{$this->directory}/keep.txt");
        $this->assertFalse($cache->has('mine'));
    }

    public function testNoTemporaryFilesAreLeft(): void
    {
        $this->cache()->set('atomic', str_repeat('x', 10_000));

        $this->assertSame([], glob("{$this->directory}/*/*.tmp") ?: []);
    }

    protected function cache(?int $defaultTtl = null): CacheInterface
    {
        $this->directory ??= sys_get_temp_dir() . '/zana-cache-' . bin2hex(random_bytes(6));

        return new FileCache($this->directory, $defaultTtl, $this->clock());
    }

    private function fileFor(string $key): string
    {
        $hash = hash('sha256', $key);

        return "{$this->directory}/{$hash[0]}{$hash[1]}/{$hash}";
    }
}
