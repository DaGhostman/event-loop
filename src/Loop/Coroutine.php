<?php

namespace Onion\Framework\Loop;

use \Fiber;
use Onion\Framework\Loop\Interfaces\{
    CoroutineInterface,
    TaskInterface as Task,
};
use Throwable;

class Coroutine implements CoroutineInterface
{
    private bool $started = false;
    private bool $suspended = false;
    private mixed $result = null;
    private ?Throwable $exception = null;

    public function __construct(private readonly Fiber $coroutine, private readonly array $args = [])
    {
    }

    public function send(mixed $result): void
    {;
        $this->suspended = false;
        $this->result = $result;
    }

    public function throw(Throwable $exception): void
    {
        $this->suspended = false;
        $this->exception = $exception;
    }

    public function run(): mixed
    {
        if (!$this->started) {
            $this->started = true;
            return $this->coroutine->start(...$this->args);
        } elseif ($this->exception) {
            $exception = $this->exception;
            $this->exception = null;
            return $this->coroutine->throw($exception);
        } else {
            $result = $this->coroutine->resume($this->result);
            $this->result = null;
            return $result;
        }
    }

    public static function task(): Task
    {
        return signal(fn (callable $resume, Task $task): mixed => $resume($task));
    }

    public function suspend(mixed $value): void
    {
        $this->suspended = true;
        if (!$this->coroutine->isSuspended()) {
            $this->coroutine->suspend($value);
        }
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
        return $this->suspended && $this->coroutine->isSuspended();
    }
}
