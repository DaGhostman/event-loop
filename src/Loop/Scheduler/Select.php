<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;

use Onion\Framework\Loop\Interfaces\{ResourceInterface, SchedulerInterface, TaskInterface};
use Onion\Framework\Loop\Scheduler\Traits\SchedulerErrorHandler;
use Onion\Framework\Loop\Scheduler\Traits\StreamNetworkUtil;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use SplQueue;
use Throwable;

class Select implements SchedulerInterface
{
    private SplQueue $queue;
    /**
     *
     * @var array<int, TaskInterface[]>
     */
    private array $timers = [];
    private bool $started = false;
    private bool $wasRunning = false;

    /**
     * Summary of signals
     * @var array<int, \SplQueue<TaskInterface>>
     */
    private array $signals;

    private int $tasks = 0;
    private int $nextTickAt = 0;

    // resourceID => [socket, tasks]
    protected array $reads = [];
    protected array $writes = [];
    protected array $closes = [];

    use SchedulerErrorHandler;
    use StreamNetworkUtil;

    public function __construct()
    {
        $this->queue = new SplQueue();
        $this->signals = [];
    }

    public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($at === null) {
            $this->tasks++;
            $this->queue->enqueue($task);
        } else {
            $this->nextTickAt = $this->nextTickAt === 0 ? $at : min($at, $this->nextTickAt);
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

        if (!$this->wasRunning) {
            $this->started = true;
            $this->wasRunning = true;
        }

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
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

        $socket = $resource->getResourceId();

        if (isset($this->reads[$socket])) {
            $this->reads[$socket][1][] = $task;
        } else {
            $this->reads[$socket] = [$resource->getResource(), [$task]];
        }
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

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
            $this->tasks--;

            if ($task->isKilled() || $task->isFinished()) {
                continue;
            }

            try {
                $result = $task->run();

                if ($result instanceof Signal) {
                    $this->schedule(
                        Task::create(\Closure::fromCallable($result), [$task, $this])
                    );
                    continue;
                }

                if (
                    !$task->isKilled() &&
                    $task->isFinished() &&
                    $task->isPersistent()
                ) {
                    $this->schedule($task->spawn());
                }
            } catch (Throwable $ex) {
                $this->triggerErrorHandlers($ex);
            }

            $this->schedule($task);
        }
    }

    /**
     * @return void
     */
    protected function ioPoll(?int $timeout): void
    {
        $rSocks = [];
        foreach ($this->reads as [$socket]) {
            if (get_resource_type($socket) === 'Unknown') {
                unset($this->reads[get_resource_id($socket)]);
                continue;
            }

            $rSocks[] = $socket;
        }

        $wSocks = [];
        foreach ($this->writes as [$socket]) {
            if (get_resource_type($socket) === 'Unknown') {
                unset($this->writes[get_resource_id($socket)]);
                continue;
            }
            $wSocks[] = $socket;
        }

        if ($timeout !== 0) {
            $timeout /= 1000;
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

            foreach ($tasks as $idx => $task) {
                if ($task->isKilled() || $task->isFinished()) {
                    continue;
                }

                $this->schedule($task->isPersistent() ? $task->spawn() : $task);
            }
        }

        foreach ($wSocks as $socket) {
            $id = get_resource_id($socket);
            [, $tasks] = $this->writes[$id];

            foreach ($tasks as $idx => $task) {
                if ($task->isKilled() || $task->isFinished()) {
                    unset($this->writes[$id][1][$idx]);
                    continue;
                }

                $this->schedule($task->isPersistent() ? $task->spawn() : $task);
            }
        }
    }

    protected function timerPoll(int $now): void
    {
        $this->nextTickAt = 0;
        foreach ($this->timers as $ts => $tasks) {
            if ($ts <= $now) {
                foreach ($tasks as $task) {
                    $this->schedule($task);
                }
                unset($this->timers[$ts]);
                continue;
            }
            $this->nextTickAt = $ts;

            break;
        }
    }

    protected function poll(): void
    {
        $tick = (int) (hrtime(true) / 1e+3);
        $isEmpty = $this->tasks === 0;
        $timeout = $isEmpty ? EVENT_LOOP_STREAM_IDLE_TIMEOUT : 0;
        if ($this->nextTickAt > 0 && $isEmpty) {
            $diff = $this->nextTickAt - $tick;
            $timeout = (int) ($diff <= 0 ? 0 : $diff);
        }

        $timeout = $timeout > EVENT_LOOP_STREAM_IDLE_TIMEOUT ? EVENT_LOOP_STREAM_IDLE_TIMEOUT : $timeout;

        $this->timerPoll($tick);
        $this->tasksPoll();
        $this->ioPoll($timeout);

        if (
            !$this->reads &&
            !$this->writes &&
            $this->tasks === 0 &&
            !$this->timers
        ) {
            $this->started = false;
        }
    }

    private function handleSignal(int $signal)
    {
        while (!$this->signals[$signal]->isEmpty()) {
            $this->schedule($this->signals[$signal]->dequeue());
        }
    }

    public function signal(int $signal, TaskInterface $task): void
    {
        if (!isset($this->signals[$signal])) {
            $this->signals[$signal] = new SplQueue();

            if (strtolower(PHP_OS_FAMILY) == 'windows') {
                sapi_windows_set_ctrl_handler($this->handleSignal(...), true);
            } elseif (extension_loaded('pcntl')) {
                pcntl_async_signals(true);
                pcntl_signal($signal, $this->handleSignal(...));
            } else {
                user_error(
                    'Signal handling is not supported, because it is not windows and pcntl extension is not loaded',
                    E_USER_WARNING
                );
            }
        }

        $this->signals[$signal]->enqueue($task);
    }
}
