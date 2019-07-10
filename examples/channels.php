<?php
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Timer;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$scheduler = new Scheduler;

$server = new Coroutine(function () {


    yield Coroutine::create(function () {
        $child = yield Coroutine::create(function () {
            while (Coroutine::isRunning()) {
                $id = yield Coroutine::getId();

                yield Timer::after(function () use ($id) {
                    while (yield Coroutine::recv($id)) {
                        echo "-";
                    }
                }, mt_rand(500, 3000));
            }
        });

        for ($i=0; $i<100; $i++) {
            yield Timer::after(function () use ($i, $child) {
                try {
                    if (($i % 2) === 0) {
                        echo "+";
                        yield Coroutine::push($child, $i);
                    }
                } catch (\Exception $ex) {
                    echo "{$ex->getMessage()}\n";
                }
            }, mt_rand(0, 1500));
        }


        yield Timer::after(function () use ($child) {
            yield Coroutine::kill($child);
        }, 10000);

    });
});

$scheduler->add($server);
$scheduler->start();
