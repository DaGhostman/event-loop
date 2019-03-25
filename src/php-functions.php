<?php
namespace Onion\Framework\EventLoop;

use Onion\Framework\EventLoop\Interfaces\LoopInterface;

if (!function_exists(__NAMESPACE__ . '\select')) {
    function select(&$read, &$write, &$error, ?int $timeout = null) {
        return @stream_select($read, $write, $error, $timeout);
    }
}

if (!function_exists(__NAMESPACE__ . '\loop')) {
    function &loop($count = 1): LoopInterface {
        static $loop = null;
        if ($loop === null) {
            $loop = new Loop($count);
        }

        return $loop;
    }
}

