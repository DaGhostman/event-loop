<?php
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Coroutine;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$scheduler = new Scheduler;

$server = new Coroutine(function () {


    yield Coroutine::create(function () {
        $child = yield Coroutine::create(function () {
            while (true) {
                if (yield Coroutine::isEmpty()) {
                    yield;
                } else {
                    var_dump(yield Coroutine::pop());
                }
            }
        });

        for ($i=0; $i<30; $i++) {
            try {
                if (($i % 5) === 0) yield Coroutine::push($child, $i);
                else yield;
            } catch (\Exception $ex) {
                echo "{$ex->getMessage()}\n";
            }
        }

        while (!yield Coroutine::isEmpty($child)) {
            yield;
        }

        yield Coroutine::kill($child);
    });
});

$scheduler->add($server);
$scheduler->start();
