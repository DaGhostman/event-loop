<?php
namespace Onion\Framework\EventLoop\Interfaces;

use Closure;

interface SchedulerInterface
{
    public function task(Closure $callback): void;
    public function defer(Closure $closure): void;
    public function interval(float $interval, Closure $callback);
    public function delay(float $delay, Closure $callback);
    public function io($resource, ?Closure $callback);

    public function attach($resource, ?Closure $onRead = null, ?Closure $onWrite = null): bool;
    public function detach($resource): bool;
}
