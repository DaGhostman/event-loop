<?php
namespace Onion\Framework\EventLoop;

use function Onion\Framework\EventLoop\coroutine;


class IoPoll extends Task
{
    private $sockets = [];

    public function __construct()
    {
        parent::__construct((function () {
            for (;;) {
                $read = [];
                $write = [];
                foreach ($this->sockets as $fd => $socket) {
                    if (!is_resource($socket[0]) || feof($socket[0])) {
                        unset($this->sockets[$fd]);
                        continue;
                    }

                    $read[] = $socket[0];
                    $write[] = $socket[0];
                    $ex = [];
                }

                if (@stream_select($read, $write, $ex, 0)) {
                    foreach ($read as $sock) {
                        $fd = (int) $sock;
                        foreach ($this->sockets[$fd][1] as $index => $task) {
                            if ($task === null) {
                                continue;
                            }

                            yield coroutine(function () use ($task, $sock) {
                                $task($this, $sock);
                            });
                            unset($this->sockets[$fd][1][$index]);
                        }
                    }

                    foreach ($write as $sock) {
                        $fd = (int) $sock;

                        foreach ($this->sockets[$fd][2] as $task) {
                            if ($task === null) {
                                continue;
                            }
                            yield coroutine(function () use ($task, $sock) {
                                $task($this, $sock);
                            });
                            unset($this->sockets[$fd][2][$index]);
                        }
                    }
                }

                yield;
            }
        })());
    }

    public function push($resource, ?\Closure $read = null, ?\Closure $write = null)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException(
                'Invalid resource provided'
            );
        }

        $descriptor = (int) $resource;

        if (!isset($this->sockets[$descriptor])) {
            $this->sockets[$descriptor] = [$resource, [$read], [$write]];
        } else {
            if ($read !== null) {
                $this->sockets[$descriptor][1][] = $read;
            }

            if ($write !== null) {
                $this->sockets[$descriptor][2][] = $write;
            }
        }
    }

    public function finished()
    {
        return empty($this->sockets);
    }
}
