<?php
namespace Onion\Framework\EventLoop\Task;

use Onion\Framework\EventLoop\Interfaces\TaskInterface;

class Task implements TaskInterface
{
    private $started = false;
    private $exception;
    private $callback;

    public function __construct(callable $closure, ...$params)
    {
        $this->callback = $this->wrap((function ($params) use ($closure) {
            yield call_user_func($closure, ...$params);
        })($params));
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
        $this->started = true;
        if (!$this->started()) {
            $this->callback->current();
        } else {
            $this->callback->next();
        }
    }

    public function throw(\Throwable $ex): void {
        $this->exception = $ex;
    }

    public function finished(): bool
    {
        return !$this->callback->valid();
    }

    public function started(): bool
    {
        return $this->started;
    }
}
