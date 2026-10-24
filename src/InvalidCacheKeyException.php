<?php

declare(strict_types=1);

namespace Zana\Cache;

/**
 * Thrown for keys PSR-16 does not allow.
 */
final class InvalidCacheKeyException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
    public static function for(string $key, string $reason): self
    {
        return new self(sprintf('Invalid cache key "%s": %s.', $key, $reason));
    }
}
