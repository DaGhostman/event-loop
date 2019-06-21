<?php
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

global $scheduler;
$scheduler = new Scheduler();


function getTaskId() {
    return new Signal(function(Task $task, Scheduler $scheduler) {
        $task->send($task->getId());
        $scheduler->schedule($task);
    });
}

function coroutine(Generator $coroutine) {
    return new Signal(
        function(Task $task, Scheduler $scheduler) use ($coroutine) {
            $task->send($scheduler->push($coroutine));
            $scheduler->schedule($task);
        }
    );
}

function killTask($tid) {
    return new Signal(
        function(Task $task, Scheduler $scheduler) use ($tid) {
            $task->send($scheduler->kill($tid));
            $scheduler->schedule($task);
        }
    );
}

// function childTask() {
//     $tid = (yield getTaskId());
//     while (true) {
//         echo "Child task $tid still alive!\n";
//         yield;
//     }
// }

// function task() {
//     global $scheduler;

//     $tid = (yield getTaskId());
//     $childTid = (yield $scheduler->push(childTask()));

//     for ($i = 1; $i <= 6; ++$i) {
//         echo "Parent task $tid iteration $i.\n";
//         yield;

//         if ($i == 3) yield killTask($childTid);
//     }
// }


// $scheduler->push(task());
// $scheduler->newTask(task(5));

function echoTimes($msg, $max) {
    $task = yield getTaskId();
    for ($i = 1; $i <= $max; ++$i) {
        echo "{$msg}({$task}) iteration $i\n";
        if ($i === 5) {
            yield killTask($task);
        }
        yield;
    }
}

function go() {
    $task = yield getTaskId();
    yield coroutine(echoTimes('foo', 10)); // print foo ten times
    echo "{$task} ---\n";
    yield coroutine(echoTimes('bar', 5)); // print bar five times
}
$scheduler->push(go());

$scheduler->run();
