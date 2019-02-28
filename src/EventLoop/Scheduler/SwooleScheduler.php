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
        $interval = (int) ($interval * 1000);
        if ($interval <= 0) {
            $interval = 1;
        }
        swoole_timer_tick($interval, $callback);
    }

    public function delay(float $delay, Closure $callback)
    {
        $delay = (int) ($delay * 1000);
        if ($delay <= 0) {
            $delay = 1;
        }
        swoole_timer_after($delay, $callback);
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
