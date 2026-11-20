<?php

declare(strict_types=1);

namespace Zana\Cache\Tests;

use PHPUnit\Framework\Attributes\After;
use Zana\Cache\AtomicCacheInterface;
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

    /**
     * 2.26, S34 and S35: processes started at the same instant take turns.
     * Before the lock, 20 such processes all read the same count.
     */
    public function testProcessesAtTheSameInstantTakeTurns(): void
    {
        $this->cache();
        $start = microtime(true) + 2;
        $processes = [];
        $pipes = [];
        for ($i = 0; $i < 20; $i++) {
            $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__ . '/Fixtures/concurrent.php', (string) $this->directory, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            $this->assertIsResource($process);
            $processes[$i] = $process;
        }
        $counts = [];
        $added = 0;
        foreach ($processes as $i => $process) {
            $out = (string) stream_get_contents($pipes[$i][1]);
            $errors = (string) stream_get_contents($pipes[$i][2]);
            proc_close($process);
            $this->assertSame('', $errors);
            $this->assertMatchesRegularExpression('/^\d+ [01]$/', $out, 'each process answered');
            [$count, $add] = explode(' ', $out);
            $counts[] = (int) $count;
            $added += (int) $add;
        }
        sort($counts);

        $this->assertSame(range(1, 20), $counts, 'every increment saw a different count');
        $this->assertSame(1, $added, 'one add() won');
        $this->assertSame(20, (new FileCache((string) $this->directory))->get('hits'));
    }

    public function testClearRemovesLockFiles(): void
    {
        $cache = $this->cache();
        $cache->increment('hits');
        $this->assertNotSame([], glob("{$this->directory}/*/*.lock") ?: []);

        $cache->clear();

        $this->assertSame([], glob("{$this->directory}/*/*") ?: []);
    }

    public function testAnUnlockableKeyRefusesRatherThanRunUnlocked(): void
    {
        $file = sys_get_temp_dir() . '/zana-cache-file-' . bin2hex(random_bytes(4));
        file_put_contents($file, 'a file where the directory should be');
        try {
            $this->expectException(\RuntimeException::class);
            (new FileCache($file))->increment('hits');
        } finally {
            @unlink($file);
        }
    }

    protected function cache(?int $defaultTtl = null): AtomicCacheInterface
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
