<?php

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;
use function Onion\Framework\Loop\tick;

use Onion\Framework\Process\Process;

require __DIR__ . '/../vendor/autoload.php';

coroutine(function () {
    $proc = Process::exec('composer', ['info']);
    $proc->unblock();
    echo "Started process: {$proc->getPid()}\n";

    coroutine(function () {
        for ($i = 0; $i < 5; $i++) {
            echo '+';
            tick();
        }
    });

    while ($proc->isAlive()) {
        $proc->wait();
        echo "Output: \n";
        while (($chunk = $proc->read(1024)) !== '') {
            echo $chunk;
            tick();
        }
        echo PHP_EOL;
    }

    echo "Exited with: {$proc->getExitCode()}\n";
});
scheduler()->start();
