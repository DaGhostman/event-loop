<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;
use EvLoop;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Scheduler\Interfaces\NetworkServerAwareSchedulerInterface;
use Onion\Framework\Loop\Scheduler\Traits\{SchedulerErrorHandler, StreamNetworkUtil};
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use Throwable;

class Ev implements SchedulerInterface
{
    private readonly EvLoop $loop;

    private array $tasks = [];
    private array $signals = [];

    private bool $started = false;

    use SchedulerErrorHandler;
    use StreamNetworkUtil;

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
                    $task = $watcher->data;
                    unset($this->tasks[$key]);

                    if ($task->isKilled() || $task->isFinished()) {
                        return;
                    }

                    try {
                        $result = $task->run();

                        if ($result instanceof Signal) {
                            $this->schedule(Task::create(\Closure::fromCallable($result), [$task, $this]));
                            return;
                        }

                        if (
                            !$task->isKilled() &&
                            $task->isFinished() &&
                            $task->isPersistent()
                        ) {
                            $this->schedule($task->spawn());
                        }

                        $this->schedule($task);
                    } catch (Throwable $e) {
                        $this->triggerErrorHandlers($e);
                    }
                },
                $task
            );
        } else {
            $this->tasks[$key] = $this->loop->idle(
                function ($watcher) use ($key) {
                    $task = $watcher->data;
                    unset($this->tasks[$key]);

                    if ($task->isKilled() || $task->isFinished()) {
                        return;
                    }

                    try {
                        $result = $task->run();
                        if ($result instanceof Signal) {
                            $this->schedule(Task::create(\Closure::fromCallable($result), [$task, $this]));
                            return;
                        }

                        if (
                            !$task->isKilled() &&
                            $task->isFinished() &&
                            $task->isPersistent()
                        ) {
                            $this->schedule($task->spawn());
                        }

                        $this->schedule($task);
                    } catch (Throwable $e) {
                        $this->triggerErrorHandlers($e);
                    }
                },
                $task
            );
        }
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

        if ($resource->eof()) {
            return;
        }

        $key = spl_object_id($task);

        $this->tasks[$key] = $this->loop->io(
            $resource->getResource(),
            \Ev::READ,
            function ($watcher) use ($key) {
                if (!$watcher->data->isPersistent()) {
                    unset($this->tasks[$key]);
                }

                $this->schedule($watcher->data->spawn(false));
            },
            $task
        );
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

        if ($resource->eof()) {
            return;
        }

        $key = spl_object_id($task);

        $this->tasks[$key] = $this->loop->io(
            $resource->getResource(),
            \Ev::WRITE,
            function ($watcher) use ($key) {
                if (!$watcher->data->isPersistent()) {
                    unset($this->tasks[$key]);
                }

                $this->schedule($watcher->data->spawn(false));
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

        $this->signals[$key] = $this->loop->signal(
            $signal,
            function ($watcher) use ($key) {
                unset($this->signals[$key]);

                $this->schedule($watcher->data);
            },
            $task,
        );
    }
}
