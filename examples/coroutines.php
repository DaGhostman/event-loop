<?php

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\tick;

require_once __DIR__ . '/../vendor/autoload.php';

// We add initial task
$t = coroutine(function () {
    echo "Parent @ - start\n";
    for ($id = 1; $id <= 10; $id++) {
        coroutine(
            function ($id) {
            // Create a child
                echo "\t = #{$id} started\n";
                tick();
                // echo "\t\t + #{$id} received: " . $channel->recv() . PHP_EOL;
                echo "\t = #{$id} exiting\n";
            },
            [$id]
        )->sync();
    }


    // await(Promise::all(...$promises));
    echo "Parent @ - end\n";
});
// scheduler()->start();
