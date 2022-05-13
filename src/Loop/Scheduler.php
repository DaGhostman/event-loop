<?php

declare(strict_types=1);

namespace Onion\Framework\Loop;

use Fiber;
use Onion\Framework\Loop\Interfaces\{CoroutineInterface, ResourceInterface, SchedulerInterface, TaskInterface};
use Onion\Framework\Loop\{Coroutine, Task};
use SplQueue;

class Scheduler implements SchedulerInterface
{
    private readonly SplQueue $queue;
    private array $timers = [];
    private bool $started = false;

    // resourceID => [socket, tasks]
    protected array $reads = [];
    protected array $writes = [];

    public function __construct()
    {
        $this->queue = new SplQueue();
        $this->queue->setIteratorMode(
            SplQueue::IT_MODE_FIFO | SplQueue::IT_MODE_DELETE
        );
    }
    public function add(CoroutineInterface $coroutine): TaskInterface
    {
        $task = new Task($coroutine);
        $this->schedule($task);

        return $task;
    }

    public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($at === 0) {
            $this->queue->enqueue($task);
        } else {
            if (!isset($this->timers[$at])) {
                $this->timers[$at] = [];
                ksort($this->timers);
            }

            $this->timers[$at][] = $task;
        }
    }

    public function start(): void
    {
        if ($this->started)
            return;

        $this->started = true;
        $this->add($this->poll());
        while (!$this->queue->isEmpty()) {
            /** @var TaskInterface $task */
            $task = $this->queue->dequeue();
            if ($task->isKilled()) {
                continue;
            }

            if ($task->isPaused()) {
                $this->schedule($task);
                continue;
            }

            try {
                $result = $task->run();

                if ($result instanceof Signal) {
                    $this->queue->enqueue(Task::create($result, [$task, $this]));
                    continue;
                }
            } catch (\Throwable $e) {
                if ($task->isFinished()) {
                    throw $e;
                }

                $task->throw($e);
                $this->schedule($task);
            }

            if ($task->isFinished()) {
                $task->kill();
            } else {
                $this->schedule($task);
            }
        }
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        $socket = $resource->getResourceId();

        if (isset($this->reads[$socket])) {
            $this->reads[$socket][1][] = $task;
        } else {
            $this->reads[$socket] = [$resource->getResource(), [$task]];
        }
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        $socket = $resource->getResourceId();

        if (isset($this->writes[$socket])) {
            $this->writes[$socket][1][] = $task;
        } else {
            $this->writes[$socket] = [$resource->getResource(), [$task]];
        }
    }

    /**
     * @return void
     */
    protected function ioPoll(?int $timeout): void
    {
        $rSocks = [];
        foreach ($this->reads as [$socket]) {
            if (is_resource($socket)) $rSocks[] = $socket;
            else unset($this->reads[(int) $socket]);
        }

        $wSocks = [];
        foreach ($this->writes as [$socket]) {
            if (is_resource($socket)) $wSocks[] = $socket;
            else unset($this->writes[(int) $socket]);
        }

        if ((empty($rSocks) && empty($wSocks)) || @!stream_select($rSocks, $wSocks, $eSocks, $timeout !== null ? 0 : null, $timeout)) {
            return;
        }

        foreach ($rSocks as $socket) {
            $id = (int) $socket;
            [, $tasks] = $this->reads[$id];
            unset($this->reads[$id]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }

        foreach ($wSocks as $socket) {
            $id = (int) $socket;
            [, $tasks] = $this->writes[$id];
            unset($this->writes[$id]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }
    }

    protected function timerPoll(int $now)
    {
        $hasPendingTimers = false;
        foreach ($this->timers as $ts => $tasks) {
            if ($ts <= $now) {
                foreach ($tasks as $task) {
                    $this->schedule($task);
                }
                unset($this->timers[$ts]);
            }
        }

        return $hasPendingTimers;
    }
    protected function poll(): CoroutineInterface
    {
        return new Coroutine(new Fiber(function () {
            while ($this->started) {
                $tick = (int) (hrtime(true) * 1e+6);
                $isEmpty = $this->queue->isEmpty();
                $timeout = $isEmpty ? null : 0;
                if (!empty($this->timers) && $isEmpty) {
                    $diff = array_key_first($this->timers) - $tick;
                    $timeout = (int) ($diff <= 0 ? 0 : $diff);
                }

                $this->timerPoll($tick);
                $this->ioPoll($timeout);
                if (
                    !$this->reads &&
                    !$this->writes &&
                    $this->queue->isEmpty() &&
                    !$this->timers
                ) {
                    return;
                }

                signal(fn (callable $resume): mixed => $resume());
            }
        }));
    }
}
