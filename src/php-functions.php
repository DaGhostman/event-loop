<?php
namespace Onion\Framework\EventLoop;

use Onion\Framework\EventLoop\Interfaces\LoopInterface;
use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;
use Onion\Framework\EventLoop\Scheduler\PhpScheduler;

if (!function_exists(__NAMESPACE__ . '\select')) {
    function select(&$read, &$write, &$error, ?int $timeout = null) {
        return stream_select($read, $write, $error, $timeout);
    }
}

if (!function_exists(__NAMESPACE__ . '\loop')) {
    function &loop(bool $newInstance = false): LoopInterface {
        static $loop = null;
        if ($newInstance || $loop === null) {
            $loop = new Loop();
        }

        return $loop;
    }
}

if (!function_exists(__NAMESPACE__ . '\scheduler')) {
    function &scheduler(Loop $loop = null): SchedulerInterface
    {
        static $scheduler = null;
        if ($scheduler === null || $loop !== null) {
            $scheduler = new PhpScheduler($loop ?? loop());
        }

        return $scheduler;
    }
}

