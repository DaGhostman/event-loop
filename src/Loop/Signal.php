<?php

namespace Onion\Framework\Loop;

use Closure;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;

class Signal
{
    protected Closure $callback;

    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }

    public function __invoke(TaskInterface $task, SchedulerInterface $scheduler): void
    {
        ($this->callback)($task, $scheduler);
    }
}
