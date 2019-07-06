<?php
namespace Onion\Framework\Loop;

class Timer
{
    public static function create(callable $coroutine, int $interval, bool $repeating = true)
    {

        $interval *= 0.001;
        $timer = function () use ($coroutine, $interval, $repeating) {
            $start = microtime(true);
            $tick = $start + $interval;

            while (true) {
                // if ($this->getTicks() === 1) {
                $result = call_user_func($coroutine);
                // }

                if ($tick <= microtime(true)) {
                    if ($result instanceof \Generator) {
                        yield from $result;
                    } else {
                        yield $result;
                    }

                    if (!$repeating) {
                        break;
                    }

                    $tick += $interval;
                }

                yield;
            }
        };

        return Coroutine::create($timer);
    }

    public static function interval(callable $coroutine, int $interval)
    {
        return static::create($coroutine, $interval, true);
    }

    public static function after(callable $coroutine, int $interval)
    {
        return static::create($coroutine, $interval, false);
    }
}
