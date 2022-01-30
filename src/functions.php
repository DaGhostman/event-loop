<?php

namespace Onion\Framework\Loop;

use Fiber;
use Onion\Framework\Loop\Channels\AbstractChannel;
use Onion\Framework\Loop\Channels\BufferedChannel;
use Onion\Framework\Loop\Channels\UnbufferedChannel;
use Onion\Framework\Loop\Interfaces\{
    ResourceInterface,
    SchedulerInterface,
    TaskInterface
};
use Onion\Framework\Loop\Scheduler;

if (!function_exists(__NAMESPACE__ . '\read')) {
    function read(
        ResourceInterface $socket,
        ?callable $coroutine = null,
    ): mixed {
        return signal(function (
            callable $resume,
            TaskInterface $task,
            SchedulerInterface $scheduler
        ) use ($coroutine, $socket) {
            $scheduler->onRead(
                $socket,
                Task::create(
                    function (
                        callable $resume,
                        callable $coroutine,
                        ResourceInterface $socket
                    ) {
                        $resume($coroutine($socket));
                    },
                    [$resume, $coroutine, $socket]
                )
            );
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\write')) {
    function write(
        ResourceInterface $socket,
        ?callable $coroutine = null,
    ): mixed {
        return signal(function (
            TaskInterface $task,
            SchedulerInterface $scheduler,
        ) use ($coroutine, $socket) {
            $scheduler->onWrite(
                $socket,
                Task::create(
                    function (
                        SchedulerInterface $scheduler,
                        TaskInterface $task,
                        callable $coroutine,
                        ResourceInterface $socket
                    ) {
                        $task->resume($coroutine($socket));
                        $scheduler->schedule($task);
                    },
                    [$scheduler, $task, $coroutine, $socket]
                )
            );
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\error')) {
    function error(
        ResourceInterface $socket,
        ?callable $coroutine = null,
    ): mixed {
        return signal(function (
            TaskInterface $task,
            SchedulerInterface $scheduler
        ) use ($coroutine, $socket): void {
            $scheduler->onError(
                $socket,
                Task::create(function (
                    SchedulerInterface $scheduler,
                    TaskInterface $task,
                    callable $coroutine,
                    ResourceInterface $socket
                ) {
                    $task->resume($coroutine($socket));
                    $scheduler->schedule($task);
                }, [$scheduler, $task, $coroutine, $socket])
            );
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\scheduler')) {
    function scheduler(
        ?SchedulerInterface $instance = null,
    ): SchedulerInterface {
        /** @var SchedulerInterface|null $scheduler */
        static $scheduler;
        if (!$scheduler) {
            $scheduler = new Scheduler();
        }

        if ($instance !== null) {
            $scheduler = $instance;
        }

        return $scheduler;
    }
}

if (!function_exists(__NAMESPACE__ . '\coroutine')) {
    function coroutine(callable $fn, array $args = []): TaskInterface
    {
        $coroutine = Task::create($fn, $args);
        scheduler()->schedule($coroutine);

        return $coroutine;
    }
}

if (!function_exists(__NAMESPACE__ . '\signal')) {
    function signal(callable $fn): mixed
    {
        return Fiber::suspend(new Signal(function (
            TaskInterface $task,
            SchedulerInterface $scheduler,
        ) use ($fn) {
            $task->suspend();

            $fn(function (mixed $value = null) use ($scheduler, $task): void {
                $task->resume($value);
                $scheduler->schedule($task);
            }, $task, $scheduler);
        }));
    }
}

if (!function_exists(__NAMESPACE__ . '\tick')) {
    function tick(): void
    {
        signal(fn (callable $resume): mixed => $resume());
    }
}

if (!function_exists(__NAMESPACE__ . '\is_readable')) {
    function is_readable(ResourceInterface $resource): bool
    {
        $modes = [
            'r' => true, 'w+' => true, 'r+' => true, 'x+' => true,
            'c+' => true, 'rb' => true, 'w+b' => true, 'r+b' => true,
            'x+b' => true, 'c+b' => true, 'rt' => true, 'w+t' => true,
            'r+t' => true, 'x+t' => true, 'c+t' => true, 'a+' => true,
            'a+b' => true,
        ];

        if (!$resource->isAlive()) {
            return false;
        }

        $metadata = stream_get_meta_data($resource->getResource());

        return isset($modes[$metadata['mode']]);
    }
}

if (!function_exists(__NAMESPACE__ . '\is_writeable')) {
    function is_writeable(ResourceInterface $resource): bool
    {
        $modes = [
            'w' => true, 'w+' => true, 'rw' => true, 'r+' => true,
            'x+' => true, 'c+' => true, 'wb' => true, 'w+b' => true,
            'r+b' => true, 'x+b' => true, 'c+b' => true, 'w+t' => true,
            'r+t' => true, 'x+t' => true, 'c+t' => true, 'a' => true,
            'a+' => true, 'a+b' => true,
        ];

        if (!$resource->isAlive()) {
            return false;
        }

        $metadata = stream_get_meta_data($resource->getResource());

        return isset($modes[$metadata['mode']]);
    }
}

if (!function_exists(__NAMESPACE__ . '\channel')) {
    function channel(int $size = null): AbstractChannel
    {
        return $size !== null ?
            new BufferedChannel($size) : new UnbufferedChannel();
    }
}
