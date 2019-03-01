<?php

namespace Onion\Framework\EventLoop;

if (extension_loaded('swoole')) {
    include __DIR__ . '/swoole-functions.php';
} else {
    include __DIR__ . '/php-functions.php';
}

if (!function_exists(__NAMESPACE__ . '\coroutine')) {
    function coroutine(\Closure $callback): void
    {
        scheduler()->task($callback);
    }
}

if (!function_exists(__NAMESPACE__ . '\after')) {
    function after(int $interval, \Closure $callback)
    {
        return scheduler()->delay($interval, $callback);
    }
}

if (!function_exists(__NAMESPACE__ . '\timer')) {
    function timer(int $interval, \Closure $callback)
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

if (!function_exists(__NAMESPACE__ . '\attach')) {
    function attach($resource, ?\Closure $onRead = null, ?\Closure $onWrite = null)
    {
        return scheduler()->attach($resource, $onRead, $onWrite);
    }
}

if (!function_exists(__NAMESPACE__ . '\detach')) {
    function detach($resource)
    {
        return scheduler()->detach($resource);
    }
}
