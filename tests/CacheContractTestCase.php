<?php

declare(strict_types=1);

namespace Zana\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;
use Zana\Cache\CacheInterface;

/**
 * The PSR-16 contract, run against each store that keeps values.
 */
abstract class CacheContractTestCase extends TestCase
{
    protected int $now = 1_000_000;

    abstract protected function cache(?int $defaultTtl = null): CacheInterface;

    public function testSetGetHasDelete(): void
    {
        $cache = $this->cache();

        $this->assertNull($cache->get('bmi'));
        $this->assertSame('none', $cache->get('bmi', 'none'));
        $this->assertFalse($cache->has('bmi'));

        $this->assertTrue($cache->set('bmi', ['value' => 22.5]));
        $this->assertSame(['value' => 22.5], $cache->get('bmi'));
        $this->assertTrue($cache->has('bmi'));

        $this->assertTrue($cache->delete('bmi'));
        $this->assertFalse($cache->has('bmi'));
        $this->assertTrue($cache->delete('bmi'), 'deleting a missing key succeeds');
    }

    public function testNullIsAValue(): void
    {
        $cache = $this->cache();
        $cache->set('nothing', null);

        $this->assertTrue($cache->has('nothing'));
        $this->assertSame('called', $cache->remember('other', null, static fn (): string => 'called'));
        $this->assertNull($cache->remember('nothing', null, static fn (): string => 'not called'));
    }

    public function testObjectsAreCopied(): void
    {
        $cache = $this->cache();
        $object = new \ArrayObject(['a' => 1]);
        $cache->set('object', $object);
        $object['a'] = 2;

        $cached = $cache->get('object');
        $this->assertInstanceOf(\ArrayObject::class, $cached);
        $this->assertSame(1, $cached['a']);
    }

    public function testTtlForms(): void
    {
        $cache = $this->cache();

        $cache->set('seconds', 'x', 10);
        $cache->set('interval', 'x', new \DateInterval('PT1M'));
        $cache->set('forever', 'x');
        $cache->set('zero', 'x', 0);
        $cache->set('existing', 'x');
        $cache->set('existing', 'y', -1);

        $this->assertFalse($cache->has('zero'), 'a TTL of zero stores nothing');
        $this->assertFalse($cache->has('existing'), 'a negative TTL deletes');

        $this->now += 11;
        $this->assertFalse($cache->has('seconds'));
        $this->assertTrue($cache->has('interval'));

        $this->now += 60;
        $this->assertFalse($cache->has('interval'));
        $this->assertTrue($cache->has('forever'));
    }

    public function testDefaultTtl(): void
    {
        $cache = $this->cache(defaultTtl: 5);
        $cache->set('short', 'x');
        $cache->set('long', 'x', 100);

        $this->now += 6;

        $this->assertFalse($cache->has('short'));
        $this->assertTrue($cache->has('long'));
    }

    public function testMultipleOperations(): void
    {
        $cache = $this->cache();

        $this->assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 'default'], $cache->getMultiple(['a', 'b', 'c'], 'default'));
        $this->assertTrue($cache->deleteMultiple(['a', 'c']));
        $this->assertSame(['a' => null, 'b' => 2], $cache->getMultiple(['a', 'b']));
    }

    public function testClear(): void
    {
        $cache = $this->cache();
        $cache->setMultiple(['a' => 1, 'b' => 2]);

        $this->assertTrue($cache->clear());
        $this->assertSame(['a' => null, 'b' => null], $cache->getMultiple(['a', 'b']));
    }

    public function testRememberStoresTheCallbackResultOnce(): void
    {
        $cache = $this->cache();
        $calls = new \ArrayObject();
        $compute = static function () use ($calls): int {
            $calls->append(true);

            return count($calls);
        };

        $first = $cache->remember('report', 30, $compute);
        $second = $cache->remember('report', 30, $compute);

        $this->assertSame([1, 1], [$first, $second]);
        $this->assertCount(1, $calls, 'the second call is a hit');

        $this->now += 31;
        $this->assertSame(2, $cache->remember('report', 30, $compute));
    }

    public function testPull(): void
    {
        $cache = $this->cache();
        $cache->set('once', 'value');

        $this->assertSame('value', $cache->pull('once'));
        $this->assertSame('gone', $cache->pull('once', 'gone'));
    }

    public function testInvalidKeysAreRejected(): void
    {
        $cache = $this->cache();

        foreach (['', str_repeat('k', 256), 'a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b', 'a:b'] as $key) {
            try {
                $cache->get($key);
                $this->fail("Key '{$key}' should be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue($cache->set(str_repeat('k', 255) . '', 'max length is fine'));
        $this->assertTrue($cache->set('patients.42_bmi-latest', 'dots, dashes and underscores are fine'));
    }

    protected function clock(): \Closure
    {
        return fn (): int => $this->now;
    }
}
