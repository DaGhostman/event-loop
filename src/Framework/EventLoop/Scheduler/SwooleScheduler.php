<?php
namespace Onion\Framework\EventLoop\Scheduler;

use GuzzleHttp\Stream\Stream;
use Onion\Framework\EventLoop\Interfaces\LoopInterface as Loop;
use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;
use Onion\Framework\EventLoop\Task\Descriptor;
use Onion\Framework\EventLoop\Task\Timer;


class SwooleScheduler implements SchedulerInterface
{
    private $loop;

    public function __construct(Loop $loop)
    {
        $this->loop = $loop;
    }

    public function task(callable $callback): void
    {
        \Swoole\Coroutine::create($callback);
    }

    public function defer(callable $callable): void
    {
        swoole_event_defer($callable);
    }

    public function interval(int $interval, callable $callback)
    {
        $timer = swoole_timer_tick($interval, $callback);

        return new class($timer) extends Timer {
            private $timer;

            public function __construct($timer) {
                $this->timer = $timer;
            }
            public function run() {}
            public function stop(): void {
                swoole_timer_clear($this->timer);
                parent::stop();
            }
        };
    }

    public function delay(int $delay, callable $callback)
    {
        $timer = swoole_timer_after($delay, $callback);

        return new class($timer) extends Timer {
            private $timer;

            public function __construct($timer) {
                $this->timer = $timer;
            }
            public function run() {}
            public function stop(): void {
                swoole_timer_clear($this->timer);
                parent::stop();
            }
        };
    }

    public function io($resource, ?callable $callback)
    {
        if (is_resource($resource)) {
            $descriptor = new Descriptor($resource, $callback);
            $this->task(function () use ($descriptor) {
                $descriptor->run();
            });
        }
    }

    public function attach($resource, ?callable $onRead = null, ?callable $onWrite = null): bool
    {
        if (!$resource || !is_resource($resource)) {
            return true;
        }

        return swoole_event_add($resource, function ($resource) use ($onRead) {
            $onRead(new Stream($resource));
        }, function ($resource) use ($onWrite) {
            $onWrite(new Stream($resource));
        });
    }

    public function detach($resource): bool
    {
        if (!$resource || !is_resource($resource) || !swoole_event_isset($resource)) {
            return true;
        }

        return swoole_event_del($resource);
    }
}
