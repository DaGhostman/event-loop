<?php

namespace Onion\Framework\Loop;

use \Fiber;
use Onion\Framework\Loop\Interfaces\{
    CoroutineInterface,
    SchedulerInterface as Scheduler,
    TaskInterface as Task,
};
use Throwable;

class Coroutine implements CoroutineInterface
{
    private bool $started = false;
    private mixed $result = null;
    private ?Throwable $exception = null;

    public function __construct(private readonly Fiber $coroutine, private readonly array $args = [])
    {
    }

    public function send(mixed $result): void
    {;
        $this->result = $result;
    }

    public function throw(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    public function run(): mixed
    {
        if (!$this->started) {
            $this->started = true;
            return $this->coroutine->start(...$this->args);
        } elseif ($this->exception) {
            $result = $this->coroutine->throw($this->exception);
            $this->exception = null;
            return $result;
        } else {
            $result = $this->coroutine->resume($this->result);
            $this->result = null;
            return $result;
        }
    }

    public function valid(): bool
    {
        return !$this->coroutine->isTerminated();
    }

    public static function task(): Task
    {
        return signal(function (Task $task, Scheduler $scheduler) {
            $task->resume($task);
            $scheduler->schedule($task);
        });
    }

    public function suspend(mixed $value): mixed
    {
        return $this->coroutine->suspend($value);
    }

    public function resume(mixed $value): mixed
    {
        return $this->coroutine->resume($value);
    }

    public function isRunning(): bool
    {
        return $this->coroutine->isStarted();
    }

    public function isTerminated(): bool
    {
        return $this->coroutine->isTerminated();
    }

    public function isPaused(): bool
    {
        return $this->coroutine->isSuspended();
    }
}
