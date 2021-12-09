<?php

declare(strict_types=1);

namespace Onion\Framework\Loop;

use Fiber;
use Onion\Framework\Loop\Interfaces\{CoroutineInterface, ResourceInterface, SchedulerInterface, TaskInterface};
use Onion\Framework\Loop\{Coroutine, Task};
use SplQueue;

class Scheduler implements SchedulerInterface
{
    private readonly \SplQueue $queue;
    private bool $started = false;

    // resourceID => [socket, tasks]
    protected array $reads = [];
    protected array $writes = [];
    protected array $errors = [];

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

    public function schedule(TaskInterface $task): void
    {
        $this->queue->enqueue($task);
    }

    public function start(): void
    {
        $this->started = true;
        $this->add($this->ioPollTask());
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
                    $result($task, $this);
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

    public function onError(ResourceInterface $resource, TaskInterface $task): void
    {
        $socket = $resource->getResourceId();

        if (isset($this->errors[$socket])) {
            $this->errors[$socket][1][] = $task;
        } else {
            $this->errors[$socket] = [$resource->getResource(), [$task]];
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

        $eSocks = [];
        foreach ($this->errors as [$socket]) {
            if (is_resource($socket)) $eSocks[] = $socket;
            else unset($this->errors[(int) $socket]);
        }

        if ((empty($rSocks) && empty($wSocks)) || @!stream_select($rSocks, $wSocks, $eSocks, $timeout)) {
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

        foreach ($eSocks as $socket) {
            $id = (int) $socket;
            [, $tasks] = $this->errors[$id];
            unset($this->errors[$id]);


            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }
    }

    protected function ioPollTask(): CoroutineInterface
    {
        return new Coroutine(new Fiber(function () {
            while ($this->started) {
                if (
                    !empty($this->reads) ||
                    !empty($this->writes) ||
                    !empty($this->errors)
                ) {
                    if ($this->queue->isEmpty()) {
                        $this->ioPoll(null);
                    } else {
                        $this->ioPoll(0);
                    }
                } else {
                    if ($this->queue->isEmpty()) {
                        return;
                    }
                }

                signal(function ($task, $scheduler) {
                    $task->resume(true);
                    $scheduler->schedule($task);
                });
            }
        }));
    }
}
