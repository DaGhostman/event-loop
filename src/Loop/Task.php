<?php

namespace Onion\Framework\Loop;

use Fiber;
use Onion\Framework\Loop\Interfaces\CoroutineInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;

class Task implements TaskInterface
{
    protected bool $killed = false;

    public function __construct(private readonly CoroutineInterface $coroutine)
    {
    }

    public function run(): mixed
    {
        return $this->coroutine->run();
    }

    public function suspend(mixed $value = null): void
    {
        if (!$this->coroutine->isPaused()) {
            $this->coroutine->suspend($value);
        }
    }

    public function resume(mixed $value = null): bool
    {
        if ($this->isKilled()) {
            return false;
        }

        $this->coroutine->send($value);

        return true;
    }

    public function throw(\Throwable $exception): bool
    {
        if ($this->isKilled()) {
            return false;
        }

        $this->coroutine->throw($exception);

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
        return $this->coroutine->isRunning() && $this->coroutine->isPaused();
    }

    public static function create(callable $fn, array $args = []): TaskInterface
    {
        return new Task(new Coroutine(new Fiber($fn), $args));
    }
}
