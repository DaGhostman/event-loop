<?php
namespace Onion\Framework\EventLoop\Task;

class Timer extends Task
{
    public const TYPE_INTERVAL = 1;
    public const TYPE_DELAY = 2;

    private $stopped = false;

    public function __construct(callable $closure, float $interval, int $options = self::TYPE_INTERVAL, ...$params)
    {
        $tick = $this->getMilliseconds() + $interval;

        $func = function (...$params) use ($closure, $tick, $interval, $options) {
            for (;;) {
                if ($this->getMilliseconds() >= $tick && !$this->stopped) {
                    call_user_func($closure, ...$params);
                    if (($options & Timer::TYPE_INTERVAL) === Timer::TYPE_INTERVAL) {
                        $tick += $interval;
                    } else {
                        $this->stop();
                        break;
                    }
                }

                yield $tick;
            }
        };

        parent::__construct($func, ...$params);
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function finished(): bool
    {
        return $this->stopped;
    }

    private function getMilliseconds(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
