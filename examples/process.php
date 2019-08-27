<?php

use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Process\Process;

use function Onion\Framework\Loop\scheduler;

require __DIR__ . '/../vendor/autoload.php';

scheduler()->add(new Coroutine(function () {
    $proc = Process::exec('composer', ['info']);
    $proc->unblock();

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
