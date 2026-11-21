<?php

declare(strict_types=1);

namespace Zana\Cache\Tests\Fixtures;

/**
 * A class whose code runs when it is unserialized, as a gadget in an attack would: it records that it ran.
 */
final class Gadget
{
    public static int $ran = 0;

    public function __wakeup(): void
    {
        self::$ran++;
    }

    public function __destruct()
    {
        self::$ran++;
    }
}
