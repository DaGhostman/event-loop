<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;
use EventBase, Event as TaskEvent;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use EventConfig;


class Event implements SchedulerInterface
{
    private EventBase $base;
    private array $tasks = [];

    private bool $started = false;

    public function __construct()
    {
        $config = new EventConfig();
        $config->requireFeatures(EventConfig::FEATURE_FDS);
        $config->requireFeatures(EventConfig::FEATURE_O1);

        $this->base = new EventBase($config);
    }


    public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($this->base->gotStop()) {
            return;
        }

        $key = spl_object_id($task);

        ($event = new TaskEvent(
            $this->base,
            -1,
            TaskEvent::TIMEOUT,
            function ($fd, $what, TaskInterface $task) use ($key) {
                $this->tasks[$key]->free();
                unset($this->tasks[$key]);

                if ($task->isKilled()) {
                    return;
                }
                $result = $task->run();

                if ($result instanceof Signal) {
                    $this->schedule(Task::create($result, [$task, $this]));
                    return;
                }

                if (!$task->isFinished()) {
                    $this->schedule($task);
                }
            },
            $task,
        ))->add($at !== null ? ($at - (hrtime(true) / 1e+3)) / 1e+6 : 0);

        $this->tasks[spl_object_id($task)] = $event;
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        $key = spl_object_id($task);

        ($event = new TaskEvent(
            $this->base,
            $resource->getResource(),
            TaskEvent::READ,
            function ($fd, $what, TaskInterface $task) use ($key) {
                $this->tasks[$key]->free();
                unset($this->tasks[$key]);

                $this->schedule($task);
            },
            $task,
        ))->add();

        $this->tasks[$key] = $event;
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        $key = spl_object_id($task);

        ($event = new TaskEvent(
            $this->base,
            $resource->getResource(),
            TaskEvent::WRITE,
            function ($fd, $what, TaskInterface $task) use ($key) {
                $this->tasks[$key]->free();
                unset($this->tasks[$key]);

                $this->schedule($task);
            },
            $task,
        ))->add();

        $this->tasks[$key] = $event;
    }

    public function start(): void {
        if ($this->started) {
            return;
        }

        $this->base->loop();
        $this->started = true;
    }

    public function stop(): void {
        if (!$this->started) {
            return;
        }

        $this->base->stop();
    }

    public function signal(int $signal, TaskInterface $task, int $priority = 0): void
    {
        $key = spl_object_id($task);

        ($event = new TaskEvent(
            $this->base,
            $signal,
            TaskEvent::SIGNAL | TaskEvent::PERSIST,
            function ($fd, $what, TaskInterface $task) use ($key) {
                $this->tasks[$key]->free();
                unset($this->tasks[$key]);

                $this->schedule($task);
            },
            $task,
        ))->add();

        $this->tasks[$key] = $event;
    }
}
