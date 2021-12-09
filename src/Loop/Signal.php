<?php

namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;

class Signal
{
    protected $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function __invoke(TaskInterface $task, SchedulerInterface $scheduler)
    {
        $callback = $this->callback;
        return $callback($task, $scheduler);
    }
}
