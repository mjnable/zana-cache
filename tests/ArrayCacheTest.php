<?php

declare(strict_types=1);

namespace Zana\Cache\Tests;

use Zana\Cache\ArrayCache;
use Zana\Cache\CacheInterface;

final class ArrayCacheTest extends CacheContractTestCase
{
    protected function cache(?int $defaultTtl = null): CacheInterface
    {
        return new ArrayCache($defaultTtl, $this->clock());
    }
}
