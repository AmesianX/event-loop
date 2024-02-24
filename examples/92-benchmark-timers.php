<?php

use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

$n = (int) ($argv[1] ?? 1000 * 100);

for ($i = 0; $i < $n; ++$i) {
    Loop::addTimer(0, function () { });
}
