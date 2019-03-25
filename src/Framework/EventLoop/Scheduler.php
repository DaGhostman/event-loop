<?php
namespace Onion\Framework\EventLoop;

use GuzzleHttp\Stream\StreamInterface;
use Onion\Framework\EventLoop\Interfaces\LoopInterface as Loop;
use Onion\Framework\EventLoop\Interfaces\SchedulerInterface;
use Onion\Framework\EventLoop\Task\Task;
use Onion\Framework\EventLoop\Task\Timer;

class Scheduler implements SchedulerInterface
{
    /** @var Loop $loop */
    private $loop;

    public function __construct(Loop $loop)
    {
        $this->loop = $loop;
    }

    public function task(callable $callback): void
    {
        $this->loop->push(new Task($callback), Loop::TASK_IMMEDIATE);
    }

    public function defer(callable $callback): void
    {
        $this->loop->push(new Task($callback), Loop::TASK_DEFERRED);
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

    public function attach(StreamInterface $resource, ?callable $onRead = null, ?callable $onWrite = null): bool
    {
        return $this->loop->attach($resource, $onRead, $onWrite);
    }

    public function detach(StreamInterface $resource): bool
    {
        return $this->loop->detach($resource);
    }

}
