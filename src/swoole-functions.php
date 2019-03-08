<?php
namespace Onion\Framework\EventLoop;

use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;
use Onion\Framework\EventLoop\Scheduler\SwooleScheduler;

if (!function_exists(__NAMESPACE__ . '\select')) {
    function select(&$read, &$write, &$error, ?int $timeout = null) {
        return @swoole_select($read, $write, $error, $timeout === null ? null : (float) $timeout);
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

