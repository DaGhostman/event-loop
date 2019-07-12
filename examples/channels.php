<?php
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Timer;
use function Onion\Framework\Loop\scheduler;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$server = new Coroutine(function () {
    yield Coroutine::create(function () {
        $child = yield Coroutine::create(function () {
            while (Coroutine::isRunning()) {

                yield Timer::after(function(int $id) {
                    while (yield Coroutine::recv($id)) {
                        echo "-";
                    }
                }, mt_rand(500, 3000), [yield Coroutine::id()]);
            }
        });

        for ($i=0; $i<100; $i++) {
            yield Timer::after(function(int $i, int $child) {
                try {
                    if (($i % 2) === 0) {
                        echo "+";
                        yield Coroutine::push($child, $i);
                    }
                } catch (\Exception $ex) {
                    echo "{$ex->getMessage()}\n";
                }
            }, mt_rand(0, 1500), [$i, $child]);
        }


        yield Timer::after(function ($child) {
            yield Coroutine::kill($child);
        }, 10000, [$child]);

    });
});

scheduler()->add($server);
scheduler()->start();
