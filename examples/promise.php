<?php

use function Onion\Framework\Loop\async;
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Promise\Promise;
use function Onion\Framework\Loop\scheduler;

require_once __DIR__ . '/../vendor/autoload.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);


$master = new Coroutine(function () {
    /** @var Promise $promise */
    $promise = yield async(function () {
        for ($i=0; $i<10; $i++) {
            sleep($i);
            echo "Tick {$i}!\n";
            yield;
        }

        return true;
    }, 2500);

    yield Coroutine::create(function () {
        for ($i=0; $i<15; $i++) {
            echo "{$i}\n";
            yield;
        }
    });

    // $promise;
    $v = yield $promise->await();
    var_dump('Hello, World!', $v);

});

scheduler()->add($master);
scheduler()->start();
