<?php
namespace Onion\Framework\Loop;

class Signal
{
    private $callback;

    public function __construct(callable $callable)
    {
        $this->callback = $callable;
    }

    public function __invoke(Task $task, Scheduler $scheduler)
    {
        return call_user_func($this->callback, $task, $scheduler);
    }
}
