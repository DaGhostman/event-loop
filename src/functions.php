<?php

namespace Onion\Framework\Loop;

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
    function read(ResourceInterface $socket, ?\Closure $coroutine = null): mixed
    {
        return signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($coroutine, $socket) {
            $scheduler->onRead($socket, Task::create(function (SchedulerInterface $scheduler, TaskInterface $task, callable $coroutine, ResourceInterface $socket) {
                $task->resume($coroutine($socket));
                $scheduler->schedule($task);
            }, [$scheduler, $task, $coroutine, $socket]));
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\write')) {
    function write(ResourceInterface $socket, \Closure $coroutine): mixed
    {
        return signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($coroutine, $socket) {
            $scheduler->onWrite($socket, Task::create(function (SchedulerInterface $scheduler, TaskInterface $task, callable $coroutine, ResourceInterface $socket) {
                $task->resume($coroutine($socket));
                $scheduler->schedule($task);
            }, [$scheduler, $task, $coroutine, $socket]));
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\error')) {
    function error(ResourceInterface $socket, \Closure $coroutine): mixed
    {
        return signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($coroutine, $socket): void {
            $scheduler->onError($socket, Task::create(function (SchedulerInterface $scheduler, TaskInterface $task, callable $coroutine, ResourceInterface $socket) {
                $task->resume($coroutine($socket));
                $scheduler->schedule($task);
            }, [$scheduler, $task, $coroutine, $socket]));
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\scheduler')) {
    function scheduler(): SchedulerInterface
    {
        static $scheduler;
        if (!$scheduler) {
            $scheduler = new Scheduler();
        }

        return $scheduler;
    }
}

if (!function_exists(__NAMESPACE__ . '\coroutine')) {
    function coroutine(\Closure $fn, array $args = []): Coroutine
    {
        $coroutine = new Coroutine(new \Fiber($fn), $args);
        scheduler()->add($coroutine);

        return $coroutine;
    }
}

if (!function_exists(__NAMESPACE__ . '\signal')) {
    function signal(\Closure $fn)
    {
        return \Fiber::suspend(new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($fn) {
            $task->suspend();
            coroutine($fn, [$task, $scheduler]);
        }));
    }
}

if (!function_exists(__NAMESPACE__ . '\tick')) {
    function tick()
    {
        signal(function ($task, $scheduler) {
            $task->resume();
            $scheduler->schedule($task);
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\is_readable')) {
    function is_readable(ResourceInterface $resource): bool
    {
        $modes = [
            'r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true,
            'c+b' => true, 'rt' => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a+' => true,
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
            'w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true,
            'c+' => true, 'wb' => true, 'w+b' => true, 'r+b' => true,
            'x+b' => true, 'c+b' => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a' => true, 'a+' => true,
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
        if ($size !== null) {
            return new BufferedChannel($size);
        }

        return new UnbufferedChannel();
    }
}
