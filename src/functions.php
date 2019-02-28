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
