<?php

declare(strict_types=1);

namespace Onion\Framework\Loop;

use Fiber;
use Onion\Framework\Loop\Interfaces\{CoroutineInterface, ResourceInterface, SchedulerInterface, TaskInterface};
use Onion\Framework\Loop\{Coroutine, Task};
use SplQueue;

class Scheduler implements SchedulerInterface
{
    private array $queue;
    private bool $started = false;

    // resourceID => [socket, tasks]
    protected array $reads = [];
    protected array $writes = [];

    public function add(CoroutineInterface $coroutine): TaskInterface
    {
        $task = new Task($coroutine);
        $this->schedule($task);

        return $task;
    }

    public function schedule(TaskInterface $task): void
    {
        $this->queue[] = $task;
    }

    public function start(): void
    {
        if ($this->started)
            return;

        $this->started = true;
        $this->add($this->ioPollTask());
        while (!empty($this->queue)) {
            /** @var TaskInterface $task */
            $task = array_shift($this->queue);
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
                    array_unshift($this->queue, Task::create($result, [$task, $this]));
                    // $result($task, $this);
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

        $eSocks = [];

        if ((empty($rSocks) && empty($wSocks) && empty($eSocks)) || @!stream_select($rSocks, $wSocks, $eSocks, $timeout)) {
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

    protected function ioPollTask(): CoroutineInterface
    {
        return new Coroutine(new Fiber(function () {
            while ($this->started) {
                $emptyQueue = empty($this->queue);
                if (
                    !empty($this->reads) ||
                    !empty($this->writes)
                ) {
                    if ($emptyQueue) {
                        $this->ioPoll(null);
                    } else {
                        $this->ioPoll(0);
                    }
                } else {
                    if ($emptyQueue) {
                        return;
                    }
                }

                signal(fn (callable $resume): mixed => $resume());
            }
        }));
    }
}
