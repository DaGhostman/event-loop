<?php

namespace Onion\Framework\Loop;

use Fiber;
use Onion\Framework\Loop\Interfaces\TaskInterface;

class Task implements TaskInterface
{
    protected $suspended = false;
    protected $killed = false;

    public function __construct(private readonly Coroutine  $coroutine)
    {
    }

    public function run()
    {
        return $this->coroutine->run();
    }

    public function suspend(mixed $value = null): mixed
    {
        $this->suspended = true;
        if (!$this->coroutine->isPaused()) {
            return $this->coroutine->suspend($value);
        }

        return null;
    }

    public function resume(mixed $value = null): bool
    {
        if ($this->isKilled()) {
            return false;
        }

        $this->suspended = false;
        $this->coroutine->send($value);

        return true;
    }

    public function throw(\Throwable $ex): bool
    {
        if ($this->isKilled()) {
            return false;
        }

        $this->suspended = false;
        $this->coroutine->throw($ex);


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
        return $this->suspended;
    }

    public static function create(callable $fn, array $args = []): TaskInterface
    {
        return new Task(new Coroutine(new Fiber($fn), $args));
    }
}
