<?php

use Onion\Framework\Loop\Channel;
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Timer;

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;
use function Onion\Framework\Loop\tick;

require_once __DIR__ . '/../vendor/autoload.php';

$scheduler = scheduler();
// We add initial task
coroutine(function () {
    echo "Parent @ - start\n";
    for ($id = 1; $id <= 10; $id++) {
        coroutine(function ($id) { // Create a child
            echo "\t = #{$id} started\n";
            tick();
            // echo "\t\t + #{$id} received: " . $channel->recv() . PHP_EOL;
            echo "\t = #{$id} exiting\n";
        }, [$id]);
        // else {
        //     Timer::after(fn (TaskInterface $task) => $task->kill(), 1500, [$child]);
        // }
    }

    echo "Parent @ - end\n";
});
scheduler()->start();
