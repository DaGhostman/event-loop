<?php
namespace Onion\Framework\EventLoop\Scheduler;

use Guzzle\Stream\StreamInterface;
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

    public function task(callable $callback): void
    {
        $worker = function () use ($callback) {
            yield $callback();
        };

        $this->loop->push(new Task($worker), Loop::TASK_IMMEDIATE);
    }

    public function defer(callable $callable): void
    {
        $worker = function () use ($callable) {
            yield $callable();
        };

        $this->loop->push(new Task($worker), Loop::TASK_DEFERRED);
    }

    public function interval(int $interval, callable $callback) {
        return $this->loop->push(
            new Timer($callback, $interval, Timer::TYPE_INTERVAL),
            Loop::TASK_IMMEDIATE
        );
    }

    public function delay(int $delay, callable $callback) {
        return $this->loop->push(
            new Timer($callback, $delay, Timer::TYPE_DELAY),
            Loop::TASK_IMMEDIATE
        );
    }

    public function io($resource, ?callable $callback)
    {
        if (is_resource($resource)) {
            $descriptor = new Descriptor($resource, $callback);
            $this->loop->push($descriptor);
        }
    }

    public function attach(StreamInterface $resource, ?callable $onRead = null, ?callable $onWrite = null): bool
    {
        return $this->loop->attach($resource, $onRead, $onWrite);
    }

    public function detach(StreamInterface $resource): bool
    {
        return $this->loop->detach($resource);
    }

}
