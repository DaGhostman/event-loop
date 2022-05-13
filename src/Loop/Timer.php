<?php

namespace Onion\Framework\Loop;

use Closure;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Interfaces\TimerInterface;
use WeakReference;

class Timer implements TimerInterface
{
    private readonly WeakReference $task;
    private function __construct(TaskInterface $task)
    {
        $this->task = WeakReference::create($task);
    }

    public function stop(): void
    {
        $this->task->get()?->kill();
    }

    private static function create(Closure $coroutine, int $interval, bool $repeating = true): TimerInterface
    {
        $task = Task::create(static function (Closure $coroutine, int $interval, bool $repeating) {
            coroutine($coroutine);
            if ($repeating) {
                scheduler()->schedule(Coroutine::task(), (int) (hrtime(true) * 1e+6) + $interval);
            }
        }, [$coroutine, $interval, $repeating]);

        scheduler()->schedule($task, (int) (hrtime(true) * 1e+6) + $interval);

        return new static($task);
    }

    public static function interval(Closure $coroutine, int $interval): TimerInterface
    {
        return static::create($coroutine, $interval, true);
    }

    public static function after(Closure $coroutine, int $interval): TimerInterface
    {
        return static::create($coroutine, $interval, false);
    }
}
