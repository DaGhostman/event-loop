<?php

use function Onion\Framework\EventLoop\after;
use function Onion\Framework\EventLoop\coroutine;
use function Onion\Framework\EventLoop\defer;
use function Onion\Framework\EventLoop\loop;
use function Onion\Framework\EventLoop\timer;

require __DIR__ . '/../vendor/autoload.php';

$timer = timer(1.0, function () {
    echo time() . PHP_EOL;

    coroutine(function () {
        echo 'Test'. PHP_EOL;

        coroutine(function () {
            echo 'Invoked!' . PHP_EOL;
        });
    });
});

after(3.0, function () use ($timer) {
    echo "Stoping timer after 3 seconds\n";
    $timer->stop();
});


// defer(function () {
//     echo 'That is all folks!';
// });

loop()->start();
// $loop->start();
