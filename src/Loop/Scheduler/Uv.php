<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;

class Uv implements SchedulerInterface
{
    private readonly mixed $loop;
    private bool $running = false;

    public function __construct()
    {
        $this->loop = uv_loop_new();
    }

    public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($at === null) {
            uv_idle_start(uv_idle_init($this->loop), function($handle) use ($task, $at) {
                uv_close($handle);

                if ($task->isKilled()) {
                    return;
                }
                $result = $task->run();

                if ($result instanceof Signal) {
                    $this->schedule(Task::create($result, [$task, $this]));
                    return;
                }

                if (!$task->isFinished()) {
                    $this->schedule($task, $at);
                }
            });
        } else {
            uv_timer_start(
                uv_timer_init($this->loop),
                (int) ($at !== null ? ($at - (hrtime(true) / 1e3)) / 1e3 : 0),
                0,
                function($handle) use ($task, $at) {
                    uv_close($handle);

                    if ($task->isKilled()) {
                        return;
                    }
                    $result = $task->run();

                    if ($result instanceof Signal) {
                        $this->schedule(Task::create($result, [$task, $this]));
                        return;
                    }

                    if (!$task->isFinished()) {
                        $this->schedule($task, $at);
                    }
                }
            );
        }
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        $poll = uv_poll_init($this->loop, $resource->getResource());
        uv_poll_start($poll, \UV::READABLE, function($poll, $stat, $ev) use ($task) {
            $this->schedule($task);
            uv_poll_stop($poll);
        });
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        $poll = uv_poll_init($this->loop, $resource->getResource());
        uv_poll_start($poll, \UV::WRITABLE, function($poll, $stat, $ev) use ($task) {
            uv_close($poll);
            uv_poll_stop($poll);

            $this->schedule($task);
        });
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        uv_run($this->loop);
        $this->running = true;
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        uv_stop($this->loop);
    }

    public function signal(int $signal, TaskInterface $task): void
    {
        uv_signal_start(uv_signal_init($this->loop), function($handle) use ($task) {
            $this->schedule($task);
            uv_close($handle);
        }, $signal);
    }
}
