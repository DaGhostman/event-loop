<?php
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface as Task;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Promise\AwaitablePromise as Promise;

if (!function_exists(__NAMESPACE__ . '/read')) {
    function _read(ResourceInterface $socket) {
        return new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($socket) {
            $scheduler->onRead($socket, $task);
        });
    }

    function read(ResourceInterface $socket, callable $coroutine) {
        yield _read($socket);

        yield call_user_func($coroutine, $socket);
    }
}

if (!function_exists(__NAMESPACE__ . '/write')) {
    function _write($socket) {
        return new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($socket) {
            $scheduler->onWrite($socket, $task);
        });
    }

    function write(ResourceInterface $socket, callable $coroutine) {
        yield _write($socket);

        yield call_user_func($coroutine, $socket);
    }
}

if (!function_exists(__NAMESPACE__ . '/async')) {
    function async(callable $callable, ?int $timeout = null, ?callable $cancelFn = null): Signal
    {
        return new Signal(function (Task $task, Scheduler $scheduler) use ($callable, $timeout, $cancelFn) {
            $coroutine = null;
            $promise = null;
            $promise = new Promise(function ($resolve, $reject) use ($scheduler, $callable, &$coroutine) {
                $coroutine = $scheduler->add(new Coroutine(function () use (&$resolve, &$reject, $callable) {
                    try {
                        $value = call_user_func($callable, $resolve, $reject);

                        if ($value instanceof \Generator) {
                            yield from $value;

                            return $resolve($value->getReturn());
                        }

                        return $resolve($value);
                    } catch (\Throwable $ex) {
                        $reject($ex);
                    }
                }));

            }, function () use (&$promise) {
                while ($promise->isPending()) {
                    yield;
                }
            }, $cancelFn);

            if ($timeout !== null) {
                $timer = $scheduler->add(new Coroutine(function () use (&$coroutine, $promise, $timeout) {
                    yield Timer::after(function () use (&$coroutine, $promise) {
                        if ($promise->isPending()) {
                            $promise->cancel();
                            yield Coroutine::kill($coroutine);
                        }
                    }, $timeout);
                }));
                $timerFn = function() use ($timer, $scheduler) {
                    echo "Stopping promise timer :)";
                    $scheduler->killTask($timer);
                };

                $promise->then($timerFn, $timerFn);
            }
            $task->send($promise);
            $scheduler->schedule($task);
        });
    }
}
