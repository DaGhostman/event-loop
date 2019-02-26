<?php
namespace Onion\Framework\EventLoop\Task;

use Closure;
use function Onion\Framework\EventLoop\coroutine;
use Onion\Framework\EventLoop\Stream\Stream;

class Descriptor extends Task
{
    private $resource;

    private $read;
    private $write;
    private $error;

    public function __construct($resource, int $timeout = null)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException(
                'Expected resource, got ' . gettype($resource)
            );
        }

        $this->resource = $resource;

        $generator = function () use (&$resource, $timeout){
            for (;;) {

                $read = [$resource];
                $write = [$resource];
                $error = [$resource];

                if (stream_select($read, $write, $error, $timeout)) {
                    if (!empty($read) && isset($this->read) && $this->read !== null) {
                        $callback = $this->read;
                        coroutine(function () use ($callback) {
                            $callback(new Stream($this->resource));
                        });
                        unset($this->read);
                    }

                    if (!empty($write) && isset($this->write) && $this->write !== null) {
                        $callback = $this->write;
                        coroutine(function () use ($callback) {
                            $callback(new Stream($this->resource));
                        });
                        unset($this->write);
                    }

                    if (!empty($error) && isset($this->error) && $this->error !== null) {
                        $callback = $this->error;
                        coroutine(function() use ($callback) {
                            $callback(new Stream($this->resource));
                        });
                        unset($this->error);
                    }
                }

                yield;

            }
        };

        parent::__construct($generator);
    }

    public function onRead(?Closure $callback)
    {
        $this->read = $callback;
    }

    public function onWrite(?Closure $callback)
    {
        $this->write = $callback;
    }

    public function onError(?Closure $callback)
    {
        $this->error = $callback;
    }

    public function finished(): bool
    {
        return (
                (!isset($this->read) || $this->read === null) &&
                (!isset($this->write) || $this->write === null) &&
                (!isset($this->error) || $this->error === null)
            ) || !is_resource($this->resource);
    }
}
