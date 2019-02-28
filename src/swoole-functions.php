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
    function &loop(bool $newInstance = false): LoopInterface {
        static $loop = null;
        if ($newInstance || $loop === null) {
            $loop = new class implements LoopInterface {
                public function start(): void {}
                public function stop(): void {}
                public function kill(): void {}
                public function push(TaskInterface $task, int $type = LoopInterface::TASK_IMMEDIATE): TaskInterface
                {
                    return $task;
                }
            };
        }

        return $loop;
    }
}

if (!function_exists(__NAMESPACE__ . '\scheduler')) {
    function &scheduler(LoopInterface $loop = null): SchedulerInterface
    {
        static $scheduler = null;
        if ($scheduler === null || $loop !== null) {
            $scheduler = new SwooleScheduler($loop ?? loop());
        }

        return $scheduler;
    }
}
