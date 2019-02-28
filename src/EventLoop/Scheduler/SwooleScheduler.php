<?php
namespace Onion\Framework\EventLoop\Scheduler;

use Closure;
use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;
use Onion\Framework\EventLoop\Task\Descriptor;
use Onion\Framework\EventLoop\Task\Timer;

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
        $timer = swoole_timer_tick($interval, $callback);

        return new class($timer) extends Timer {
            private $timer;

            public function __construct($timer) {
                $this->timer = $timer;
            }
            public function run() {}
            public function stop() {
                swoole_timer_clear($this->timer);
                parent::stop();
            }
        };
    }

    public function delay(float $delay, Closure $callback)
    {
        $delay = (int) ($delay * 1000);
        if ($delay <= 0) {
            $delay = 1;
        }
        $timer = swoole_timer_after($delay, $callback);

        return new class($timer) extends Timer {
            private $timer;

            public function __construct($timer) {
                $this->timer = $timer;
            }
            public function run() {}
            public function stop() {
                swoole_timer_clear($this->timer);
                parent::stop();
            }
        };
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
