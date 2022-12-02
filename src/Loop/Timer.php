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

    private static function create(Closure $coroutine, int $ms, bool $repeating = true): TimerInterface
    {
        // Convert milliseconds to microseconds
        $ms *= 1000;
        $task = Task::create(
            static function (Closure $coroutine, int $ms, bool $repeating) use (&$tick) {
                $tick = ((int) floor(hrtime(true) / 1e+3));
                coroutine($coroutine);

                if ($repeating) {
                    while (true) {
                        signal(
                            fn ($resume, $task, $scheduler) => $scheduler->schedule(
                                Task::create($resume),
                                $tick + $ms,
                            )
                        );
                        $tick = ((int) floor(hrtime(true) / 1e+3));
                        coroutine($coroutine);
                    }
                }
            },
            [$coroutine, $ms, $repeating]
        );

        scheduler()->schedule($task, ((int) floor(hrtime(true) / 1e+3)) + $ms);

        return new static($task);
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
