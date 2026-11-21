<?php

declare(strict_types=1);

namespace Zana\Cache;

use Zana\Configuration\ConfigurationInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\ServiceProvider;

/**
 * Registers CacheManager, and the default store as CacheInterface and the
 * PSR-16 interface; and cache.file_signing for `check` (2.28).
 */
final class CacheServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(CacheManager::class, static fn (ServiceResolverInterface $r): CacheManager => new CacheManager(
                $r->get(ConfigurationInterface::class),
                $r->get(ApplicationInterface::class)->basePath(),
            ))->uses(ConfigurationInterface::class, ApplicationInterface::class)
            ->singleton(CacheInterface::class, static fn (ServiceResolverInterface $r): CacheInterface => $r->get(CacheManager::class)->store())->uses(CacheManager::class)
            ->alias(\Psr\SimpleCache\CacheInterface::class, CacheInterface::class)
            ->singleton(Inspection\FileSigningCheck::class)->tag(ApplicationCheck::TAG, Inspection\FileSigningCheck::class);
    }

    public function origin(): string
    {
        return 'package:mj-zana/cache';
    }
}
