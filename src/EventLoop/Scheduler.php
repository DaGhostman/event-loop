<?php
namespace Onion\Framework\EventLoop;

use Closure;
use Onion\Framework\EventLoop\Task\Task;
use Onion\Framework\EventLoop\Task\Timer;
use Onion\Framework\EventLoop\Task\Descriptor;


class Scheduler
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

    public function interval(float $interval, Closure $callback) {
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

    public function delay(float $delay, Closure $callback) {
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

    public function io($resource, int $timeout, ?Closure $read = null, ?Closure $write = null, ?Closure $error = null)
    {
        if (is_resource($resource)) {
            $descriptor = new Descriptor($resource, $timeout);
            $descriptor->onRead($read);
            $descriptor->onWrite($write);
            $descriptor->onError($error);

            $this->loop->push($descriptor);
        }
    }

}
