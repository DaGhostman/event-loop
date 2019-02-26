<?php
namespace Onion\Framework\EventLoop;

use Onion\Framework\EventLoop\Descriptor;
use Onion\Framework\EventLoop\Loop;


if (!function_exists(__NAMESPACE__ . '\loop')) {
    function &loop(bool $newInstance = false): Loop {
        static $loop = null;
        if ($newInstance || $loop === null) {
            $loop = new Loop();
        }

        return $loop;
    }

    if (!function_exists(__NAMESPACE__ . '\coroutine')) {
        function coroutine(\Closure $callback)
        {
            return loop()->push($callback);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\after')) {
        function after(int $interval, \Closure $callback)
        {
            return loop()->delay($interval, $callback);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\timer')) {
        function timer(int $interval, \Closure $callback)
        {
            return loop()->interval($interval, $callback);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\defer')) {
        function defer(\Closure $callback)
        {
            return loop()->defer($callback);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\io')) {
        function io($resource, ?\Closure $read = null, ?\Closure $write = null, ?\Closure $error = null)
        {
            $descriptor = new Descriptor($resource);

            if ($read !== null) {
                $descriptor->onRead($read);
            }

            if ($write !== null) {
                $descriptor->onWrite($write);
            }

            if ($error !== null) {
                $descriptor->onError($error);
            }

            return loop()->io($descriptor);
        }
    }
}

