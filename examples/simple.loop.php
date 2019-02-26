<?php

use function Onion\Framework\Common\Loop\after;
use function Onion\Framework\Common\Loop\coroutine;
use function Onion\Framework\Common\Loop\defer;
use function Onion\Framework\Common\Loop\loop;
use function Onion\Framework\Common\Loop\timer;

require __DIR__ . '/../vendor/autoload.php';

$timer = timer(1, function () {
    echo time() . PHP_EOL;

    coroutine(function () {
        echo 'Test'. PHP_EOL;

        coroutine(function () {
            echo 'Invoked!' . PHP_EOL;
        });
    });
});

after(3, function () use ($timer) {
    echo "Stoping timer after 3 seconds\n";
    $timer->stop();
});


// defer(function () {
//     echo 'That is all folks!';
// });

loop()->start();
// $loop->start();
