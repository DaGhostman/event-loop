<?php
namespace Onion\Framework\EventLoop\Task;

class Timer extends Task
{
    public const TYPE_INTERVAL = 1;
    public const TYPE_DELAY = 2;

    private $interval;
    private $options = 0;
    private $tick;
    private $stopped = false;

    public function __construct(\Closure $closure, float $interval, int $options = self::TYPE_INTERVAL)
    {
        parent::__construct($closure);
        $this->interval = $interval;
        $this->tick = $this->getMilliseconds() + $this->interval;
        $this->options = $options;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function run()
    {
        if ($this->getMilliseconds() >= $this->tick) {
            $value = parent::run();

            if (($this->options & self::TYPE_INTERVAL) == self::TYPE_INTERVAL) {
                $this->tick += $this->interval;
            } else {
                $this->stop();
            }

            return $value;
        }
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
