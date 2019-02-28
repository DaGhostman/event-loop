<?php
namespace Onion\Framework\EventLoop\Scheduler;

use Closure;
use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;
use Onion\Framework\EventLoop\Task\Descriptor;

class SwooleScheduler implements SchedulerInterface
{
    public function task(Closure $callback): void
    {
        \Swoole\Coroutine::create($callback);
    }

    public function defer(Closure $closure): void
    {
        swoole_event_defer($closure);
    }

    public function interval(float $interval, Closure $callback)
    {
        swoole_timer_tick((int) ($interval * 1000), $callback);
    }

    public function delay(float $delay, Closure $callback)
    {
        swoole_timer_after((int) ($delay * 1000), $callback);
    }

    public function io($resource, ?Closure $callback)
    {
        if (is_resource($resource)) {
            $descriptor = new Descriptor($resource, $callback);
            $this->task(function () use ($descriptor) {
                $descriptor->run();
            });
        }
    }
}
