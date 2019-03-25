<?php

namespace Onion\Framework\EventLoop;

use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Stream\StreamInterface;
use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;

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

if (!function_exists(__NAMESPACE__ . '\attach')) {
    function attach($resource, ?callable $onRead = null, ?callable $onWrite = null)
    {
        if (!$resource instanceof StreamInterface) {
            if (!is_resource($resource)) {
                throw new \InvalidArgumentException(sprintf(
                    'Expected instance of %s or type resource, %s given',
                    StreamInterface::class,
                    gettype($resource)
                ));
            }

            $resource = new Stream($resource);
        }

        return scheduler()->attach($resource, $onRead, $onWrite);
    }
}

if (!function_exists(__NAMESPACE__ . '\detach')) {
    function detach($resource)
    {
        if (!$resource instanceof StreamInterface) {
            if (!is_resource($resource)) {
                throw new \InvalidArgumentException(sprintf(
                    'Expected instance of %s or type resource, %s given',
                    StreamInterface::class,
                    gettype($resource)
                ));
            }

            $resource = new Stream($resource);
        }

        return scheduler()->detach($resource);
    }
}

if (!function_exists(__NAMESPACE__ . '\scheduler')) {
    function &scheduler(): SchedulerInterface
    {
        static $scheduler = null;
        if ($scheduler === null) {
            $scheduler = new Scheduler(loop());
        }

        return $scheduler;
    }
}
