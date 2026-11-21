<?php

declare(strict_types=1);

namespace Zana\Cache\Inspection;

use Zana\Cache\CacheManager;
use Zana\Cache\FileCache;
use Zana\Configuration\ConfigurationInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;

/**
 * cache.file_signing (2.28): a file store signs its files. A warning, as an
 * unsigned store still works: without a key, a file written to the cache
 * directory by anything but the application is unserialized, which can run
 * code. A key shorter than FileCache::MINIMUM_KEY_LENGTH is an error, as the
 * store refuses it.
 */
final readonly class FileSigningCheck implements ApplicationCheck
{
    public function findings(ApplicationInterface $application): array
    {
        $config = $application->container()->get(ConfigurationInterface::class);
        assert($config instanceof ConfigurationInterface);

        $files = [];
        foreach ($config->array('cache.stores', []) as $name => $settings) {
            if (is_array($settings) && ($settings['driver'] ?? null) === 'file') {
                $files[] = (string) $name;
            }
        }
        if ($files === []) {
            return [];   // array and null stores never write a file
        }

        $key = CacheManager::fileKey($config);
        if ($key === null) {
            return [new Finding(
                'cache.file_signing',
                Severity::Warning,
                'The file cache (' . implode(', ', $files) . ') is not signed, so a file written to its directory by anything but the application is unserialized, which can run code.',
                hint: 'Set security.secret, or cache.key.',
            )];
        }
        if (strlen($key) < FileCache::MINIMUM_KEY_LENGTH) {
            return [new Finding(
                'cache.file_signing',
                Severity::Error,
                'cache.key is shorter than ' . FileCache::MINIMUM_KEY_LENGTH . ' characters, so the file cache refuses it.',
                hint: 'Generate one with: php -r "echo bin2hex(random_bytes(32));"',
            )];
        }

        return [];
    }
}
