<?php
namespace Onion\Framework\EventLoop;

class Task
{
    private $started;
    private $exception;
    private $callback;

    public function __construct(\Generator $closure)
    {
        $this->callback = $this->wrap($closure);
    }

    private function wrap(\Generator $generator)
    {
        $stack = new \SplStack();

        for (;;) {
            $value = $generator->current();

            if ($value instanceof \Generator) {
                $stack->push($value);
                $generator = $value;
                continue;
            }

            if (!$generator->valid()) {
                if ($stack->isEmpty()) {
                    return;
                }

                $generator = $stack->pop();
                $generator->send($value);
                continue;
            }

            $generator->send(yield $generator->key() => $value);
        }
    }

    public function run()
    {
        if (!$this->started()) {
            $this->started = true;
            return $this->callback->current();
        } else {
            return $this->callback->next();
        }
    }

    public function throw(\Throwable $ex) {
        $this->exception = $ex;
    }

    public function finished()
    {
        return !$this->callback->valid();
    }

    public function started()
    {
        return $this->started;
    }
}
