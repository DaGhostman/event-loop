<?php

namespace Onion\Framework\Loop;

use Generator;
use Onion\Framework\Loop\Interfaces\SchedulerInterface as Scheduler;
use Onion\Framework\Loop\Interfaces\TaskInterface as Task;
use Onion\Framework\Loop\Signal;
use RuntimeException;
use SplStack;
use Throwable;

class Coroutine
{
    /** @var \Generator $coroutine */
    private $coroutine;

    /** @var int $ticks */
    private $ticks = 0;

    /** @var mixed $result */
    private $result;

    /** @var Descriptor $Descriptor */
    private $descriptor;

    /** @var Channel $Descriptor */
    private $channel;

    /** @var \Throwable $exception */
    private $exception;

    /** @var \Generator $source */
    private $source;


    public function __construct(callable $coroutine, ?Channel $channel = null)
    {
        $this->channel = $channel ?? new Channel();

        $coroutine = call_user_func($coroutine, $this->descriptor);

        if (!$coroutine instanceof \Generator) {
            throw new \InvalidArgumentException(
                "Provided callable must return an instance of Generator"
            );
        }

        $this->source = $coroutine;
        $this->coroutine = $this->wrap($coroutine);
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function send($result): void
    {
        $this->result = $result;
    }

    public function throw(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    public function run()
    {
        if ($this->ticks === 0) {
            $this->ticks++;
            return $this->coroutine->current();
        } elseif ($this->exception) {
            $result = $this->coroutine->throw($this->exception);
            $this->exception = null;
            return $result;
        } else {
            $result = $this->coroutine->send($this->result);
            $this->result = null;
            return $result;
        }
    }

    public function valid(): bool
    {
        return $this->coroutine->valid() || $this->coroutine->valid();
    }

    public static function create(callable $coroutine, ?Descriptor $descriptor = null): Signal
    {
        return new Signal(function (Task $task, Scheduler $scheduler) use ($descriptor, $coroutine) {
            $task->send($scheduler->add(new static($coroutine, $descriptor)));
            $scheduler->schedule($task);
        });
    }

    public static function channel(?int $id = null)
    {
        return new Signal(function (Task $task, Scheduler $scheduler) use ($id) {
            $task->send($scheduler->getTask(($id ?? $task->getId()))->getChannel());
            $scheduler->schedule($task);
        });
    }

    public static function recv(?int $coroutine = null): Signal
    {
        return new Signal(function (Task $task, Scheduler $scheduler) use ($coroutine) {
            $scheduler->add(new Coroutine(function () use ($task, $scheduler, $coroutine) {
                $task->send(yield from (
                    $scheduler->getTask(($coroutine ?? $task->getId()))->getChannel()->recv()
                ));
                $scheduler->schedule($task);
            }));
        });
    }

    public static function id(): Signal
    {
        return new Signal(function (Task $task, Scheduler $scheduler) {
            $task->send($task->getId());
            $scheduler->schedule($task);
        });
    }

    public static function suspend(?int $id = null): Signal
    {
        return new Signal(function (Task $task, Scheduler $scheduler) use ($id) {
            $t = $scheduler->getTask($id ?? $task->getId());
            if ($t->suspend()) {
                $scheduler->schedule($task);
            } else {
                throw new \LogicException('Unable to suspend completed coroutine');
            }
        });
    }

    public static function resume(?int $id = null): Signal
    {
        return new Signal(function (Task $task, Scheduler $scheduler) use ($id) {
            if ($scheduler->getTask($id ?? $task->getId())->resume()) {
                $scheduler->schedule($task);
            } else {
                throw new \LogicException('Unable to resume completed coroutine');
            }
        });
    }

    public static function kill(?int $id = null): Signal
    {
        return new Signal(function (Task $task, Scheduler $scheduler) use ($id) {
            if ($scheduler->killTask($id ?? $task->getId())) {
                $scheduler->schedule($task);
            } else {
                throw new RuntimeException("Unable to kill coroutine {$id}");
            }
        });
    }

    public static function isRunning(?int $id = null): Signal
    {
        return new Signal(function (Task $task, Scheduler $scheduler) use ($id) {
            $t = $scheduler->getTask($id ?? $task->getId());
            $task->send(!$t->isFinished());
            $scheduler->schedule($task);
        });
    }

    protected function getTicks(): int
    {
        return $this->ticks;
    }

    private function wrap(\Generator $generator)
    {
        $stack = new SplStack;
        $exception = null;

        while (true) {
            try {
                if ($exception) {
                    $generator->throw($exception);
                    $exception = null;
                    continue;
                }

                $value = $generator->current();

                if ($value instanceof Generator) {
                    $stack->push($generator);
                    $generator = $value;
                    continue;
                }

                if (!$generator->valid()) {
                    if ($stack->isEmpty()) {
                        return;
                    }

                    $generator = $stack->pop();
                    $generator->send($value ? $value->getReturn() : null);
                    continue;
                }

                try {
                    $result = (yield $generator->key() => $value);
                } catch (\Throwable $e) {
                    $generator->throw($e);
                    continue;
                }

                $generator->send($result);
            } catch (\Throwable $ex) {
                if ($stack->isEmpty()) {
                    throw $ex;
                }

                $generator = $stack->pop();
                $exception = $ex;
            }
        }
    }
}
