<?php

namespace Onion\Framework\Loop;

use Closure;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Interfaces\TimerInterface;
use WeakReference;

/**
 * @psalm-consistent-constructor
 */
class Timer implements TimerInterface
{
    private readonly WeakReference $task;
    final private function __construct(TaskInterface $task)
    {
        $this->task = WeakReference::create($task);
    }

    public function stop(): void
    {
        $this->task->get()?->stop();
    }

    private static function create(Closure $coroutine, int $ms, bool $repeating = true): static
    {
        // Convert milliseconds to microseconds
        $ms *= 1000;
        $task = Task::create(
            static function (Closure $coroutine, int $ms, bool $repeating) {
                $tick = ((int) floor(hrtime(true) / 1e+3));
                coroutine($coroutine);

                if ($repeating) {
                    scheduler()->schedule(Task::current()->spawn(false), $tick + $ms);
                }
            },
            [$coroutine, $ms, $repeating],
        );

        $timer = new static($task);

        scheduler()->schedule($task, ((int) floor(hrtime(true) / 1e+3)) + $ms);

        return $timer;
    }

    public static function interval(Closure $coroutine, int $ms): TimerInterface
    {
        return static::create($coroutine, $ms, true);
    }

    public static function after(Closure $coroutine, int $ms): TimerInterface
    {
        return static::create($coroutine, $ms, false);
    }
}
