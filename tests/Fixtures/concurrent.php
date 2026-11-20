<?php

declare(strict_types=1);

// One of FileCacheTest's concurrent processes: waits for the shared start time, then one increment() and one add()
// on the same keys as the others. Prints "<count> <1 if add() won, else 0>".

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $directory, $start] = $_SERVER['argv'];
$cache = new Zana\Cache\FileCache($directory);
while (microtime(true) < (float) $start) {
    usleep(200);
}
echo $cache->increment('hits', 1, 900), ' ', $cache->add('once', true, 900) ? '1' : '0';
