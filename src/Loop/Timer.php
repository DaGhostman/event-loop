<?php

namespace Onion\Framework\Loop;

use Closure;

class Timer
{
    public static function create(callable $coroutine, int $interval, bool $repeating = true, array $args = []): void
    {

        $interval *= 0.001;
        $timer =
            function (callable $coroutine, float $interval, bool $repeating, array $args): void {
                $start = microtime(true);
                $tick = $start + $interval;

                while (true) {
                    if ($tick >= microtime(true)) {
                        tick();
                        continue;
                    }
                    $coroutine(...$args);

                    if (!$repeating) {
                        break;
                    }

                    $tick = microtime(true) + $interval;

                    usleep(500);
                }
            };

        coroutine($timer, [$coroutine, $interval, $repeating, $args]);
    }

    public static function interval(callable $coroutine, int $interval, array $args = []): void
    {
        static::create($coroutine, $interval, true, $args);
    }

    public static function after(callable $coroutine, int $interval, array $args = []): void
    {
        static::create($coroutine, $interval, false, $args);
    }
}
