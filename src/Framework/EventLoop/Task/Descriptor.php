<?php
namespace Onion\Framework\EventLoop\Task;

use Closure;
use function Onion\Framework\EventLoop\coroutine;
use function Onion\Framework\EventLoop\select;
use Guzzle\Stream\Stream;

class Descriptor extends Task
{
    public function __construct($resource, Closure $callback)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException(
                'Expected resource, got ' . gettype($resource)
            );
        }

        $generator = function () use (&$resource, $callback) {

            $error = [$resource];
            $read = [$resource];
            $write = [$resource];

            if (@select($read, $write, $error, null) !== false) {
                $stream = new Stream($resource);

                yield coroutine(function () use ($callback, $stream) {
                    return $callback($stream);
                });
            }
        };

        parent::__construct($generator);
    }
}
