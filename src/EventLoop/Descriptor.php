<?php
namespace Onion\Framework\EventLoop;

use function Onion\Framework\EventLoop\coroutine;


class Descriptor extends Task
{
    private $resource;

    private $read;
    private $write;
    private $error;

    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException(
                'Expected resource, got ' . gettype($resource)
            );
        }

        $this->resource = $resource;

        $generator = function () {
            for (;;) {
                $read = [$this->resource];
                $write = [$this->resource];
                $error = [$this->resource];

                if (is_resource($this->resource) && stream_select($read, $write, $error, 0)) {
                    if (!empty($read) && $this->read !== null) {
                        yield coroutine(function () {
                            ($this->read)($this->resource, $this);
                        });
                        $this->read = null;
                    }

                    if (!empty($write) && $this->write !== null) {
                        yield coroutine(function () {
                            ($this->write)($this->resource, $this);
                        });
                        $this->write = null;
                    }

                    if (!empty($error) && $this->error !== null) {
                        yield coroutine(function() {
                            ($this->error)($this->resource, $this);
                        });
                        $this->error = null;
                    }
                }

                yield;
            }
        };
        parent::__construct($generator());
    }

    public function onRead(\Closure $callback)
    {
        $this->read = $callback;
    }

    public function onWrite(\Closure $callback)
    {
        $this->write = $callback;
    }

    public function onError(\Closure $callback)
    {
        $this->error = $callback;
    }

    public function finished()
    {
        return !is_resource($this->resource) || (
            $this->error && $this->read && $this->write
        );
    }
}
