<?php

use function Onion\Framework\Loop\scheduler;
use Onion\Framework\Loop\Coroutine;

use Onion\Framework\Process\Process;

require __DIR__ . '/../vendor/autoload.php';

scheduler()->add(new Coroutine(function () {
    $proc = Process::exec('composer', ['info']);
    $proc->unblock();
    $proc->onError(function ($s) {
        echo "Error: " . $s->read(8192);
    });
    echo "Started process: {$proc->getPid()}\n";

    while ($proc->isAlive()) {
        yield $proc->wait();
        echo "Output: \n";
        while (($chunk = $proc->read(1024)) !== '') {
            echo $chunk;
        }
        echo PHP_EOL;
    }

    echo "Exited with: {$proc->getExitCode()}\n";
}));
scheduler()->start();
