<?php
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface as Task;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Promise\AwaitablePromise as Promise;

if (!function_exists(__NAMESPACE__ . '\read')) {
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

if (!function_exists(__NAMESPACE__ . '\write')) {
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

if (!function_exists(__NAMESPACE__ . '\scheduler')) {
    function scheduler(): SchedulerInterface {
        static $scheduler;
        if (!$scheduler) {
            $scheduler = new Scheduler;
        }

        return $scheduler;
    }
}

if (!function_exists(__NAMESPACE__ . '\async')) {
    function async(callable $callable, ?int $timeout = null, ?callable $cancelFn = null): Signal
    {
        return new Signal(function (Task $task, Scheduler $scheduler) use ($callable, $timeout, $cancelFn) {
            $coroutine = null;
            $promise = null;
            $promise = new class(function ($resolve, $reject) use ($callable, &$coroutine) {
                $coroutine = coroutine(function (callable $callback) use (&$resolve, &$reject) {
                    try {
                        $value = call_user_func($callback);

                        if ($value instanceof \Generator) {
                            yield from $value;

                            return $resolve($value->getReturn());
                        }

                        return $resolve($value);
                    } catch (\Throwable $ex) {
                        $reject($ex);
                    }
                }, [$callable]);

            }, function () use (&$promise) {
                while ($promise->isPending()) {
                    yield;
                }
            }, $cancelFn) extends Promise {
                private $waitFn;

                public function __construct(callable $task, callable $waitFn, callable $cancelFn = null)
                {
                    $this->waitFn = $waitFn;
                    parent::__construct($task, $waitFn, $cancelFn);
                }

                public function await()
                {
                    if ($this->isPending() && is_callable($this->waitFn)) {
                        yield call_user_func($this->waitFn);

                        if ($this->getValue() instanceof AwaitableInterface) {
                            yield $this->getValue()->await();
                        }
                    }

                    if ($this->isFulfilled()) {
                        return $this->getValue();
                    }

                    if ($this->isRejected()) {
                        throw $this->getValue();
                    }

                    throw new \RuntimeException("Waiting on {$this->getState()} promise failed");
                }
            };

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
                    $scheduler->killTask($timer);
                };

                $promise->then($timerFn, $timerFn);
            }
            $task->send($promise);
            $scheduler->schedule($task);
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\coroutine')) {
    function coroutine(callable $fn, array $args = []): int {
        return scheduler()->add(new Coroutine($fn, $args));
    }
}

if (!function_exists(__NAMESPACE__ . '\is_readable')) {
    function is_readable(ResourceInterface $resource): bool {
        $modes = [
            'r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true,
            'c+b' => true, 'rt' => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a+' => true,
        ];

        $metadata = stream_get_meta_data($resource->getDescriptor());

        return isset($modes[$metadata['mode']]);
    }
}

if (!function_exists(__NAMESPACE__ . '\is_writeable')) {
    function is_writeable(ResourceInterface $resource): bool {
        $modes = [
            'w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true,
            'c+' => true, 'wb' => true, 'w+b' => true, 'r+b' => true,
            'x+b' => true, 'c+b' => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a' => true, 'a+' => true,
        ];

        $metadata = stream_get_meta_data($resource->getDescriptor());

        return isset($modes[$metadata['mode']]);
    }
}
