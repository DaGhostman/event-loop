<?php
namespace Onion\Framework\EventLoop;

use Onion\Framework\EventLoop\Interfaces\LoopInterface;
use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;
use Onion\Framework\EventLoop\Interfaces\TaskInterface;
use Onion\Framework\EventLoop\Scheduler\SwooleScheduler;

if (!function_exists(__NAMESPACE__ . '\select')) {
    function select(&$read, &$write, &$error, ?int $timeout = null) {
        return @swoole_select($read, $write, $error, $timeout === null ? null : (float) $timeout);
    }
}

if (!function_exists(__NAMESPACE__ . '\loop')) {
    function &loop(): LoopInterface {
        static $loop = null;
        if ($loop === null) {
            $loop = new class implements LoopInterface
            {
                public function start(): void
                {
                    swoole_event_wait();
                }

                public function stop(): void
                {
                    swoole_event_exit();
                }

                public function attach($resource, ?\Closure $onRead = null, ?\Closure $onWrite = null): bool
                {
                    return attach($resource, $onRead, $onWrite);
                }

                public function detach($resource): bool
                {
                    return detach($resource);
                }

                public function tick(): void
                {
                    swoole_event_wait();
                }

                public function push(TaskInterface $task, int $type = self::TASK_IMMEDIATE): TaskInterface
                {
                    return $task;
                }
            };
        }

        return $loop;
    }
}

if (!function_exists(__NAMESPACE__ . '\scheduler')) {
    function &scheduler(): SchedulerInterface
    {
        static $scheduler = null;
        if ($scheduler === null) {
            $scheduler = new SwooleScheduler(loop());
        }

        return $scheduler;
    }
}

