<?php

declare(strict_types=1);

namespace Zana\Cache\Tests;

use PHPUnit\Framework\Attributes\After;
use Zana\Cache\AtomicCacheInterface;
use Zana\Cache\FileCache;
use Zana\Cache\Tests\Fixtures\Gadget;

/**
 * 2.28: a file cache with a key signs its files, and unserializes only those it signed. The whole PSR-16 contract
 * runs signed, and then each way of planting a file.
 */
final class SignedFileCacheTest extends CacheContractTestCase
{
    private const string KEY = 'a key of at least thirty-two characters, for the tests';

    private ?string $directory = null;

    #[After]
    public function removeDirectory(): void
    {
        Gadget::$ran = 0;
        if ($this->directory === null || !is_dir($this->directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    public function testAPlantedFileIsNeverUnserialized(): void
    {
        $cache = $this->cache();
        $this->assertTrue($cache->signs());
        $cache->set('role', 'editor');

        // What someone who can write to the cache directory, but has not the key, would put there: before 2.28,
        // the gadget's code ran on the next read.
        file_put_contents($this->fileFor('role'), serialize([null, new Gadget()]));
        Gadget::$ran = 0;

        $this->assertNull($cache->get('role'));
        $this->assertSame(0, Gadget::$ran, 'the planted object was never built');
        $this->assertFileDoesNotExist($this->fileFor('role'), 'and the file is removed');
    }

    public function testAForgedSignatureIsRefused(): void
    {
        $cache = $this->cache();
        $cache->set('role', 'editor');
        $file = $this->fileFor('role');
        $signed = (string) file_get_contents($file);

        // The signature kept, the payload changed.
        file_put_contents($file, str_replace(serialize('editor'), serialize('admin!'), $signed));
        $this->assertNull($cache->get('role'), 'a changed value');

        // A signature computed without the key, in the same format.
        $payload = serialize([null, 'admin']);
        file_put_contents($file, 'zc1.' . hash_hmac('sha256', "role\0{$payload}", 'not the key') . '.' . $payload);
        $this->assertNull($cache->get('role'), 'another key');

        foreach (['zc1.', 'zc1..', 'zc1.' . str_repeat('0', 64), 'zc1.' . str_repeat('0', 64) . '.' . $payload] as $broken) {
            file_put_contents($file, $broken);
            $this->assertFalse($cache->has('role'), "broken: {$broken}");
        }
    }

    public function testAFileMovedToAnotherKeyIsRefused(): void
    {
        $cache = $this->cache();
        $cache->set('role_ada', 'admin');
        $cache->set('role_grace', 'reader');
        @mkdir(dirname($this->fileFor('role_grace')), 0775, true);

        // A valid file, signed by the application, copied to where another key's file goes.
        copy($this->fileFor('role_ada'), $this->fileFor('role_grace'));

        $this->assertNull($cache->get('role_grace'));
        $this->assertSame('admin', $cache->get('role_ada'), 'the original still reads');
    }

    public function testFilesFromBefore228AreMissesAndTheCacheFillsAgain(): void
    {
        $this->directory = sys_get_temp_dir() . '/zana-cache-' . bin2hex(random_bytes(6));
        $unsigned = new FileCache($this->directory, null, $this->clock());
        $unsigned->set('page', 'old');
        $this->assertFalse($unsigned->signs());

        $signed = $this->cache();
        $this->assertNull($signed->get('page'), 'unsigned: a miss');
        $this->assertFileDoesNotExist($this->fileFor('page'));
        $signed->set('page', 'new');
        $this->assertSame('new', $signed->get('page'));

        // And back: a store without the key does not read what it cannot check.
        $this->assertNull($unsigned->get('page'));
    }

    public function testAnotherKeyReadsNothing(): void
    {
        $this->cache()->set('page', 'value');
        $this->directory ??= '';
        $other = new FileCache($this->directory, null, $this->clock(), str_repeat('k', 32));

        $this->assertNull($other->get('page'));
    }

    public function testAShortKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 characters');
        new FileCache(sys_get_temp_dir(), key: str_repeat('k', 31));
    }

    public function testAddAndIncrementRefuseAPlantedFileToo(): void
    {
        $cache = $this->cache();
        $cache->set('attempts', 0);
        file_put_contents($this->fileFor('attempts'), serialize([null, 1000]));

        $this->assertSame(1, $cache->increment('attempts', ttl: 60), 'a planted count is not read: the count starts again');
        @mkdir(dirname($this->fileFor('token')), 0775, true);
        file_put_contents($this->fileFor('token'), serialize([null, true]));
        $this->assertTrue($cache->add('token', true, 60), 'a planted "already used" is not read');
    }

    protected function cache(?int $defaultTtl = null): FileCache
    {
        $this->directory ??= sys_get_temp_dir() . '/zana-cache-' . bin2hex(random_bytes(6));

        return new FileCache($this->directory, $defaultTtl, $this->clock(), self::KEY);
    }

    private function fileFor(string $key): string
    {
        $hash = hash('sha256', $key);

        return "{$this->directory}/{$hash[0]}{$hash[1]}/{$hash}";
    }
}
