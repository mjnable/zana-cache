<?php

declare(strict_types=1);

namespace Zana\Cache;

use Zana\Configuration\ConfigurationInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\ServiceProvider;

/**
 * Registers CacheManager, and the default store as CacheInterface and the PSR-16 interface.
 */
final class CacheServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(CacheManager::class, static fn (ServiceResolverInterface $r): CacheManager => new CacheManager(
                $r->get(ConfigurationInterface::class),
                $r->get(ApplicationInterface::class)->basePath(),
            ))
            ->singleton(CacheInterface::class, static fn (ServiceResolverInterface $r): CacheInterface => $r->get(CacheManager::class)->store())
            ->alias(\Psr\SimpleCache\CacheInterface::class, CacheInterface::class);
    }

    public function origin(): string
    {
        return 'package:mj-zana/cache';
    }
}
