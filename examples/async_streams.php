<?php

declare(strict_types=1);

use function Onion\Framework\Loop\{coroutine, tick};

require_once __DIR__ . '/../vendor/autoload.php';

coroutine(function () {
    for ($i = 0; $i < 10; $i++) {
        echo "#{$i}\n";
        tick();
    }
});

coroutine(function () {
    $file = fopen(__DIR__ . '/async_file.txt', 'a+');

    for ($i = 0; $i < 10; $i++) {
        fwrite($file, (string) $i);
        echo "writing to file\n";
    }
});
