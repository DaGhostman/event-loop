<?php
namespace Onion\Framework\EventLoop;

class Timer extends Task
{
    public const TYPE_INTERVAL = 1;
    public const TYPE_DELAY = 2;

    private $interval;
    private $options = 0;
    private $tick;
    private $stopped = false;

    public function __construct(\Generator $generator, int $interval, int $options = self::TYPE_INTERVAL)
    {
        parent::__construct($generator);
        $this->interval = (double) $interval;
        $this->tick = microtime(true) + $this->interval;
        $this->options = $options;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function run()
    {
        if (microtime(true) >= $this->tick) {
            $value = parent::run();

            if (($this->options & self::TYPE_INTERVAL) == self::TYPE_INTERVAL) {
                $this->tick += $this->interval;
            } else {
                $this->stop();
            }

            return $value;
        }
    }

    public function continue()
    {
        if (microtime(true) >= $this->tick) {
            $value = parent::continue();

            if (($this->options & self::TYPE_INTERVAL) == self::TYPE_INTERVAL) {
                $this->tick += $this->interval;
            } else {
                $this->stop();
            }

            return $value;
        }
    }

    public function finished()
    {
        return $this->stopped;
    }
}
