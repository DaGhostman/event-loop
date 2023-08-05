<?php

namespace Onion\Framework\Loop;

use Closure;
use Fiber;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Util\Registry;
use Onion\Framework\Promise\Deferred;
use Onion\Framework\Promise\Interfaces\DeferredInterface;
use Throwable;

class Task implements TaskInterface
{
    private ?Throwable $exception = null;
    private mixed $value = null;

    private ?DeferredInterface $deferred = null;
    private Fiber $coroutine;

    private array $metadata;

    private function __construct(
        private readonly Closure $fn,
        private readonly mixed $args,
        private readonly Registry $state,
        private readonly bool $persistent = false,
    ) {
        $this->coroutine = new Fiber($fn);
        $this->metadata = [
            'ticks' => 0,
            'duration' => 0.0,
        ];
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function run(): mixed
    {
        if ($this->exception) {
            return $this->coroutine->throw($this->exception);
        }

        $tick = \hrtime(true);
        try {
            if (!$this->coroutine->isStarted()) {
                $result = $this->coroutine->start(...$this->args);
                $this->metadata['ticks']++;
                $this->metadata['duration'] += \hrtime(true) - $tick;
            } else {
                $result = $this->coroutine->resume($this->value);
                $this->metadata['ticks']++;
                $this->metadata['duration'] += \hrtime(true) - $tick;
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
        if ($this->isKilled() || $this->isFinished()) {
            return false;
        }

        $this->value = $value;

        return true;
    }

    public function throw(Throwable $exception): bool
    {
        if ($this->isKilled() || $this->isFinished()) {
            return false;
        }

        $this->exception = $exception;

        return true;
    }

    public function kill(): void
    {
        $this->state->set('killed', true);
    }

    public function isKilled(): bool
    {
        return $this->state->get('killed', false);
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
        return signal(function ($resume) {
            while (!$this->coroutine->isTerminated()) {
                suspend();
            }

            $resume($this->coroutine->getReturn());
        });
    }

    public function spawn(bool $persistent = null): TaskInterface
    {
        return new self($this->fn, $this->args, state: $this->state, persistent: $persistent ?? $this->persistent);
    }

    public static function create(Closure $fn, array $args = [], bool $persistent = false): self
    {
        return new Task($fn, $args, new Registry(), $persistent);
    }

    public static function current(): TaskInterface
    {
        return signal(function (Closure $resume, TaskInterface $task) {
            $resume($task);
        });
    }

    public static function state(): Registry
    {
        return signal(function (Closure $resume, TaskInterface $task) {
            /** @var static $task */
            $resume($task->state);
        });
    }

    public static function info(): array
    {
        return signal(function (Closure $resume, TaskInterface $task) {
            /** @var static $task */
            $resume($task->metadata);
        });
    }

    public static function stop(): void
    {
        signal(fn (Closure $resume, TaskInterface $task)  => $resume($task->kill()));
    }
}
