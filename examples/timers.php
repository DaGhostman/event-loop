<?php

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;

use Onion\Framework\Loop\Timer;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

coroutine(function () {
    Timer::after(function () {
        var_dump(microtime(true));
    }, 1000);

    Timer::interval(function () {
        var_dump(microtime(true));
    }, 500);
});

scheduler()->start();
