<?php

declare(strict_types=1);

namespace Zana\Cache\Tests;

use Zana\Cache\ArrayCache;
use Zana\Cache\AtomicCacheInterface;

final class ArrayCacheTest extends CacheContractTestCase
{
    protected function cache(?int $defaultTtl = null): AtomicCacheInterface
    {
        return new ArrayCache($defaultTtl, $this->clock());
    }
}
