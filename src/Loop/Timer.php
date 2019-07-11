<?php
namespace Onion\Framework\Loop;

class Timer
{
    public static function create(callable $coroutine, int $interval, bool $repeating = true, array $args = []): Signal
    {

        $interval *= 0.001;
        $timer = function ($coroutine, $interval, $repeating, $args) {
            $start = microtime(true);
            $tick = $start + $interval;

            while (true) {
                // if ($this->getTicks() === 1) {
                $result = call_user_func($coroutine, ...$args);
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

        return Coroutine::create($timer, [$coroutine, $interval, $repeating, $args]);
    }

    public static function interval(callable $coroutine, int $interval, array $args = []): Signal
    {
        return static::create($coroutine, $interval, true, $args);
    }

    public static function after(callable $coroutine, int $interval, array $args = []): Signal
    {
        return static::create($coroutine, $interval, false, $args);
    }
}
