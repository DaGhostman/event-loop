<?php

namespace Onion\Framework\Loop;

use Closure;
use Fiber;
use Onion\Framework\Loop\Debug\TraceableTask;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Promise\Deferred;
use Onion\Framework\Promise\Interfaces\DeferredInterface;
use Throwable;

class Task implements TaskInterface
{
    private ?Throwable $exception = null;
    private mixed $value = null;

    protected bool $killed = false;

    private ?DeferredInterface $deferred = null;

    public function __construct(
        private readonly Fiber $coroutine,
        private readonly mixed $args,
    ) {
    }

    public function run(): mixed
    {
        if ($this->exception) {
            return $this->coroutine->throw($this->exception);
        }

        try {
            if (!$this->coroutine->isStarted()) {
                $result = $this->coroutine->start(...$this->args);
            } else {
                $result = $this->coroutine->resume($this->value);
            }

            if ($this->exception === null && $this->coroutine->isTerminated()) {
                $this->deferred?->resolve($this->coroutine->getReturn());
            }

            return $result;
        } catch (Throwable $ex) {
            $this->deferred?->reject($ex);

            throw $ex;
        }
    }

    public function suspend(mixed $value = null): void
    {
        if (!$this->coroutine->isSuspended()) {
            $this->coroutine->suspend($value);
        }
    }

    public function resume(mixed $value = null): bool
    {
        if ($this->isKilled()) {
            return false;
        }

        $this->value = $value;

        return true;
    }

    public function throw(Throwable $exception): bool
    {
        if ($this->isKilled()) {
            return false;
        }

        $this->exception = $exception;

        return true;
    }

    public function kill(): void
    {
        $this->killed = true;
    }

    public function isKilled(): bool
    {
        return $this->killed || $this->coroutine->isTerminated();
    }

    public function isFinished(): bool
    {
        return $this->coroutine->isTerminated();
    }

    public function isPaused(): bool
    {
        return $this->coroutine->isStarted() && $this->coroutine->isSuspended();
    }

    public function deferred(): DeferredInterface
    {
        if (!isset($this->deferred)) {
            if ($this->coroutine->isTerminated()) {
                throw new \LogicException(
                    'Unable to create a promise for completed task'
                );
            }

            $this->deferred = new Deferred();
        }

        return $this->deferred;
    }

    public static function defer(): DeferredInterface
    {
        return signal(function (Closure $resume, TaskInterface $task) {
            $resume($task->deferred());
        });
    }

    public function sync(): mixed
    {
        while (!$this->coroutine->isTerminated()) {
            suspend();
        }

        return $this->coroutine->getReturn();
    }

    public static function create(callable $fn, array $args = []): self
    {
        return EVENT_LOOP_TRACE_TASKS ?
            new TraceableTask(new Fiber($fn), $args) : new Task(new Fiber($fn), $args);
    }
}
