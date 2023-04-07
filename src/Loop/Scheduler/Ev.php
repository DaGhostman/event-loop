<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;
use EvLoop;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;

class Ev implements SchedulerInterface
{
    private readonly EvLoop $loop;

    private array $tasks = [];

    private bool $started = false;

    public function __construct()
    {
        $this->loop = new EvLoop();
    }

    public function schedule(TaskInterface $task, int $at = null): void
    {
        $key = spl_object_id($task);

        if ($at !== null) {
            $this->tasks[$key] = $this->loop->timer(
                ($at - (hrtime(true) / 1e3)) / 1e6,
                0,
                function ($watcher) use ($key) {
                    unset($this->tasks[$key]);

                    if ($watcher->data->isKilled()) {
                        return;
                    }
                    $result = $watcher->data->run();

                    if ($result instanceof Signal) {
                        $this->schedule(Task::create($result, [&$watcher->data, $this]));
                        return;
                    }

                    if (!$watcher->data->isFinished()) {
                        $this->schedule($watcher->data);
                    }
                },
                $task
            );
        } else {
            $this->tasks[$key] = $this->loop->idle(
                function ($watcher) use ($key) {
                    unset($this->tasks[$key]);

                    if ($watcher->data->isKilled()) {
                        return;
                    }

                    $result = $watcher->data->run();
                    if ($result instanceof Signal) {
                        $this->schedule(Task::create($result, [$watcher->data, $this]));
                        return;
                    }

                    if (!$watcher->data->isFinished()) {
                        $this->schedule($watcher->data);
                    }
                },
                $task
            );
        }
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->eof()) {
            return;
        }

        $key = spl_object_id($task);

        $this->tasks[$key] = $this->loop->io(
            $resource->getResource(),
            \Ev::READ,
            function ($watcher) use ($key) {
                unset($this->tasks[$key]);

                $this->schedule($watcher->data);
            },
            $task
        );
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->eof()) {
            return;
        }

        $key = spl_object_id($task);

        $this->tasks[$key] = $this->loop->io(
            $resource->getResource(),
            \Ev::WRITE,
            function ($watcher) use ($key) {
                unset($this->tasks[$key]);

                $this->schedule($watcher->data);
            },
            $task
        );
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->loop->run();
    }

    public function stop(): void
    {
        if (!$this->started) {
            return;
        }

        $this->loop->stop();
    }

    public function signal(int $signal, TaskInterface $task): void
    {
        $key = spl_object_id($task);

        $this->tasks[$key] = $this->loop->signal(
            $signal,
            function ($watcher) use ($key) {
                unset($this->tasks[$key]);

                $this->schedule($watcher->data);
            },
            $task,
        );
    }
}
