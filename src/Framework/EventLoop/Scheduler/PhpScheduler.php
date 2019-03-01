<?php
namespace Onion\Framework\EventLoop\Scheduler;

use Closure;
use Onion\Framework\EventLoop\Interfaces\LoopInterface as Loop;
use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;
use Onion\Framework\EventLoop\Task\Descriptor;
use Onion\Framework\EventLoop\Task\Task;
use Onion\Framework\EventLoop\Task\Timer;


class PhpScheduler implements SchedulerInterface
{
    /** @var Loop $loop */
    private $loop;

    public function __construct(Loop $loop)
    {
        $this->loop = $loop;
    }

    public function task(Closure $callback): void
    {
        $worker = function () use ($callback) {
            yield $callback();
        };

        $this->loop->push(new Task($worker), Loop::TASK_IMMEDIATE);
    }

    public function defer(Closure $closure): void
    {
        $worker = function () use ($closure) {
            yield $closure();
        };

        $this->loop->push(new Task($worker), Loop::TASK_DEFERRED);
    }

    public function interval(int $interval, Closure $callback) {
        $worker = function () use ($callback) {
            for (;;) {
                yield $callback();
            }
        };

        return $this->loop->push(
            new Timer($worker, $interval, Timer::TYPE_INTERVAL),
            Loop::TASK_IMMEDIATE
        );
    }

    public function delay(int $delay, Closure $callback) {
        $worker = function () use ($callback) {
            for (;;) {
                yield $callback();
            }
        };

        return $this->loop->push(
            new Timer($worker, $delay, Timer::TYPE_DELAY),
            Loop::TASK_IMMEDIATE
        );
    }

    public function io($resource, ?Closure $callback)
    {
        if (is_resource($resource)) {
            $descriptor = new Descriptor($resource, $callback);
            $this->loop->push($descriptor);
        }
    }

    public function attach($resource, ?Closure $onRead = null, ?Closure $onWrite = null): bool
    {
        return $this->loop->attach($resource, $onRead, $onWrite);
    }

    public function detach($resource): bool
    {
        return $this->loop->detach($resource);
    }

}
