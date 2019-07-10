<?php
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Timer;

require_once __DIR__ . '/../vendor/autoload.php';

$scheduler = new Scheduler;

// We add initial task
$scheduler->add(new Coroutine(function () use (&$scheduler) {
    $id = yield Coroutine::id(); // Retrieve the current Coroutine ID
    echo "Parent @{$id}- start\n";
    for ($i=0; $i<10; $i++) {
        $child = yield Coroutine::create(function () { // Create a child
            $id = yield Coroutine::id(); // Get child ID

            echo "\t = #{$id} started\n";
            echo "\t\t + #{$id} received: " . (yield Coroutine::recv()) . PHP_EOL;
            echo "\t = #{$id} exiting..\n";
        });
        echo "\t = #{$child} created\n";

        if ($i % 2 === 0) {
            yield (yield Coroutine::channel($child))->send($i);
        } else {
            yield Timer::after(function () use ($child, &$scheduler) {
                yield Coroutine::kill($child);
            }, 1500);
        }
    }



    echo "Parent @{$id} - end\n";
}));
$scheduler->start();
