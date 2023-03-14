<?php

declare(strict_types=1);

namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Interfaces\{ResourceInterface, SchedulerInterface, TaskInterface};
use Onion\Framework\Loop\Task;
use SplQueue;
use Throwable;

class Scheduler implements SchedulerInterface
{
    private SplQueue $queue;
    private array $timers = [];
    private bool $started = false;

    // resourceID => [socket, tasks]
    protected array $reads = [];
    protected array $writes = [];

    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($at === null) {
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
        if ($this->started) {
            return;
        }

        $this->started = true;

        while ($this->started) {
            $this->poll();
        }
    }

    public function stop(): void
    {
        $this->started = false;
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

    protected function tasksPoll(): void
    {
        $frame = $this->queue;
        $this->queue = new SplQueue();

        while (!$frame->isEmpty()) {
            /** @var TaskInterface $task */
            $task = $frame->dequeue();

            if ($task->isKilled()) {
                continue;
            }

            try {
                $result = $task->run();

                if ($result instanceof Signal) {
                    try {
                        $this->queue->enqueue(
                            Task::create($result, [$task, $this])
                        );
                        continue;
                    } catch (Throwable $ex) {
                        if (!$task->throw($ex)) {
                            throw $ex;
                        }
                    }
                }
            } catch (Throwable $ex) {
                if (!$task->throw($ex)) {
                    throw $ex;
                }

                $this->schedule($task);
            }

            if (!$task->isFinished()) {
                $this->schedule($task);
            }
        }
    }

    /**
     * @return void
     */
    protected function ioPoll(?int $timeout): void
    {
        $rSocks = [];
        foreach ($this->reads as [$socket]) {
            if (is_resource($socket)) {
                $rSocks[] = $socket;
            } else {
                unset($this->reads[get_resource_id($socket)]);
            }
        }

        $wSocks = [];
        foreach ($this->writes as [$socket]) {
            if (is_resource($socket)) {
                $wSocks[] = $socket;
            } else {
                unset($this->writes[get_resource_id($socket)]);
            }
        }

        if (
            (empty($rSocks) && empty($wSocks)) ||
            @!stream_select($rSocks, $wSocks, $eSocks, $timeout !== null ? 0 : null, $timeout)
        ) {
            return;
        }

        foreach ($rSocks as $socket) {
            $id = get_resource_id($socket);
            [, $tasks] = $this->reads[$id];
            unset($this->reads[$id]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }

        foreach ($wSocks as $socket) {
            $id = get_resource_id($socket);
            [, $tasks] = $this->writes[$id];
            unset($this->writes[$id]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }
    }

    protected function timerPoll(int $now)
    {
        $first = array_key_first($this->timers);
        if ($first > $now) {
            return;
        }

        foreach ($this->timers as $ts => $tasks) {
            if ($ts <= $now) {
                foreach ($tasks as $task) {
                    $this->schedule($task);
                }
                unset($this->timers[$ts]);
                continue;
            }

            break;
        }
    }

    protected function poll(): void
    {
        $tick = (int) (hrtime(true) / 1e+3);
        $isEmpty = $this->queue->isEmpty();
        $timeout = $isEmpty ? EVENT_LOOP_STREAM_IDLE_TIMEOUT : 0;
        if (!empty($this->timers) && $isEmpty) {
            $diff = array_key_first($this->timers) - $tick;
            $timeout = (int) ($diff <= 0 ? 0 : $diff);
        }

        $this->timerPoll($tick);
        $this->tasksPoll();
        $this->ioPoll($timeout);

        if (
            !$this->reads &&
            !$this->writes &&
            $this->queue->isEmpty() &&
            !$this->timers
        ) {
            $this->started = false;
        }
    }
}
