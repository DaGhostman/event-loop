<?php
namespace Onion\Framework\EventLoop\Interfaces;

use GuzzleHttp\Stream\StreamInterface;

interface SchedulerInterface
{
    public function task(callable $callback, ...$params): void;
    public function defer(callable $callable, ...$params): void;
    public function interval(int $interval, callable $callback, ...$params);
    public function delay(int $delay, callable $callback, ...$params);

    public function attach(StreamInterface $resource, ?callable $onRead = null, ?callable $onWrite = null): bool;
    public function detach(StreamInterface $resource): bool;
}
