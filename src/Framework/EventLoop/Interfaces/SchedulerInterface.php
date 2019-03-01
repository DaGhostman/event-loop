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
}
