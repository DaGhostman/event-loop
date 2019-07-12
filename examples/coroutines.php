<?php
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Timer;
use function Onion\Framework\Loop\scheduler;

require_once __DIR__ . '/../vendor/autoload.php';

// We add initial task
scheduler()->add(new Coroutine(function () {
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
            yield Timer::after(function (int $child) {
                yield Coroutine::kill($child);
            }, 1500, [$child]);
        }
    }



    echo "Parent @{$id} - end\n";
}));
scheduler()->start();
