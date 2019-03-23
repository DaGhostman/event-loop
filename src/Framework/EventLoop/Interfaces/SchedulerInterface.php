<?php
namespace Onion\Framework\EventLoop\Interfaces;

use Guzzle\Stream\StreamInterface;

interface SchedulerInterface
{
    public function task(callable $callback): void;
    public function defer(callable $callable): void;
    public function interval(int $interval, callable $callback);
    public function delay(int $delay, callable $callback);
    public function io($resource, ?callable $callback);

    public function attach(StreamInterface $resource, ?callable $onRead = null, ?callable $onWrite = null): bool;
    public function detach(StreamInterface $resource): bool;
}
