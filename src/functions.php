<?php
namespace Onion\Framework\EventLoop;

use Onion\Framework\EventLoop\Loop;


if (!function_exists(__NAMESPACE__ . '\loop')) {
    function &loop(bool $newInstance = false): Loop {
        static $loop = null;
        if ($newInstance || $loop === null) {
            $loop = new Loop();
        }

        return $loop;
    }

    function &scheduler(bool $newInstance = false): Scheduler
    {
        static $scheduler = null;
        if ($newInstance || $scheduler === null) {
            $scheduler = new Scheduler(loop());
        }

        return $scheduler;
    }

    if (!function_exists(__NAMESPACE__ . '\coroutine')) {
        function coroutine(\Closure $callback): void
        {
            scheduler()->task($callback);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\after')) {
        function after(float $interval, \Closure $callback)
        {
            return scheduler()->delay($interval, $callback);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\timer')) {
        function timer(float $interval, \Closure $callback)
        {
            return scheduler()->interval($interval, $callback);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\defer')) {
        function defer(\Closure $callback)
        {
            return scheduler()->defer($callback);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\io')) {
        function io($resource, \Closure $callback)
        {
            return scheduler()->io($resource, $callback);
        }
    }
}

