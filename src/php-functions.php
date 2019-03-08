<?php
namespace Onion\Framework\EventLoop;

use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;
use Onion\Framework\EventLoop\Scheduler\PhpScheduler;

if (!function_exists(__NAMESPACE__ . '\select')) {
    function select(&$read, &$write, &$error, ?int $timeout = null) {
        return @stream_select($read, $write, $error, $timeout);
    }
}

if (!function_exists(__NAMESPACE__ . '\scheduler')) {
    function &scheduler(): SchedulerInterface
    {
        static $scheduler = null;
        if ($scheduler === null) {
            $scheduler = new PhpScheduler(loop());
        }

        return $scheduler;
    }
}

