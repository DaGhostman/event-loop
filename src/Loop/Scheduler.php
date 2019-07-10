<?php
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Task;
use SplQueue;

class Scheduler implements SchedulerInterface
{
    private $maxTaskId = 0;
    private $taskMap = []; // taskId => task
    private $taskQueue;

    private $started = false;

    // resourceID => [socket, tasks]
    protected $readTasks = [];
    protected $writeTasks = [];
    protected $exceptTasks = [];

    public function __construct() {
        $this->taskQueue = new SplQueue();
    }

    public function add(Coroutine $coroutine): int
    {
        $tid = ++$this->maxTaskId;
        $task = new Task($tid, $coroutine);
        $this->taskMap[$tid] = $task;
        $this->schedule($task);
        return $tid;
    }

    public function getTask(int $id): TaskInterface
    {
        if (!isset($this->taskMap[$id])) {
            throw new \InvalidArgumentException(
                "No task with ID {$id} is running"
            );
        }

        return $this->taskMap[$id];
    }

    public function schedule(TaskInterface $task): void
    {
        $this->taskQueue->enqueue($task);
    }

    public function start(): void {
        $this->started = true;
        $this->add($this->ioPollTask());
        while (!$this->taskQueue->isEmpty()) {
            $task = $this->taskQueue->dequeue();
            if ($task->isKilled()) {
                continue;
            }

            if ($task->isPaused()) {
                $this->schedule($task);
                continue;
            }

            $result = $task->run();

            if ($result instanceof Signal) {
                try {
                    $result($task, $this);
                } catch (\Throwable $e) {
                    $task->throw($e);
                    $this->schedule($task);
                }
                continue;
            }


            if ($task->isFinished()) {
                $this->killTask($task->getId());
            } else {
                $this->schedule($task);
            }
        }
    }

    public function killTask($tid) {
        if (!isset($this->taskMap[$tid])) {
            return false;
        }

        $this->taskMap[$tid]->kill();
        return true;
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        $socket = $resource->getDescriptorId();

        if (isset($this->readTasks[$socket])) {
            $this->readTasks[$socket][1][] = $task;
        } else {
            $this->readTasks[$socket] = [$resource->getDescriptor(), [$task]];
        }
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        $socket = $resource->getDescriptorId();

        if (isset($this->writeTasks[$socket])) {
            $this->writeTasks[$socket][1][] = $task;
        } else {
            $this->writeTasks[$socket] = [$resource->getDescriptor(), [$task]];
        }
    }

    public function onExcept(ResourceInterface $resource, TaskInterface $task): void
    {
        $socket = $resource->getDescriptorId();

        if (isset($this->exceptTasks[$socket])) {
            $this->exceptTasks[$socket][1][] = $task;
        } else {
            $this->exceptTasks[$socket] = [$resource->getDescriptor(), [$task]];
        }
    }

    protected function ioPoll($timeout) {
        $rSocks = [];
        foreach ($this->readTasks as list($socket)) {
            $rSocks[] = $socket;
        }

        $wSocks = [];
        foreach ($this->writeTasks as list($socket)) {
            $wSocks[] = $socket;
        }

        $eSocks = [];
        foreach ($this->exceptTasks as list($socket)) {
            $eSocks[] = $socket;
        }

        if ((empty($rSocks) && empty($wSocks)) || @!stream_select($rSocks, $wSocks, $eSocks, $timeout)) {
            return;
        }

        foreach ($rSocks as $socket) {
            list(, $tasks) = $this->readTasks[(int) $socket];
            unset($this->readTasks[(int) $socket]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }

        foreach ($wSocks as $socket) {
            list(, $tasks) = $this->writeTasks[(int) $socket];
            unset($this->writeTasks[(int) $socket]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }

        foreach ($eSocks as $socket) {
            list(, $tasks) = $this->writeTasks[(int) $socket];
            unset($this->exceptTasks[(int) $socket]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }
    }

    protected function ioPollTask() {
        return new Coroutine(function () {
            while ($this->started) {
                if (!empty($this->readTasks) || !empty($this->writeTasks)) {

                    if ($this->taskQueue->isEmpty()) {
                        $this->ioPoll(null);
                    } else {
                        $this->ioPoll(0);
                    }
                } else {
                    if ($this->taskQueue->isEmpty()) {
                        return;
                    }
                }

                yield;
            }
        });
    }

    public function __debugInfo()
    {
        return [
            'executed' => $this->maxTaskId,
            'running' => count($this->taskQueue),
        ];
    }

}
