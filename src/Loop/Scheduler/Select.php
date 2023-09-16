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
    use SchedulerErrorHandler;
    use StreamNetworkUtil;

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
    protected \WeakMap $readSockets;
    protected \WeakMap $writeSockets;


    protected array $closes = [];

    public function __construct()
    {
        $this->queue = new SplQueue();
        $this->readSockets = new \WeakMap();
        $this->writeSockets = new \WeakMap();
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
            $this->schedule($task->spawn(false));
            return;
        }

        if (!isset($this->readSockets[$resource])) {
            $this->readSockets[$resource] = [];
        }

        $this->readSockets[$resource][] = $task;
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

        if (!isset($this->writeSockets[$resource])) {
            $this->writeSockets[$resource] = [];
        }

        $this->writeSockets[$resource][] = $task;
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
                    $this->schedule($task->spawn(false));
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
        $rTasks = [];
        $fdMap = [];
        foreach ($this->readSockets as $socket => $tasks) {
            /** @var ResourceInterface $socket */
            $fd = $socket->getResourceId();

            if ($socket->eof()) {
                unset($this->readSockets[$socket]);
                continue;
            }

            $rSocks[] = $socket->getResource();
            $rTasks[$fd] = $tasks;
            $fdMap[$fd] = $socket;
        }

        $wSocks = [];
        $wTasks = [];
        foreach ($this->writeSockets as $socket => $tasks) {
            $fd = $socket->getResourceId();

            if ($socket->eof()) {
                unset($this->writeSockets[$socket]);
                continue;
            }

            $wSocks[] = $socket->getResource();
            $wTasks[$fd] = $tasks;
            $fdMap[$fd] = $socket;
        }

        if (PHP_OS_FAMILY === 'windows') {
            $eSocks = $wSocks;
        }

        if (empty($rSocks) && empty($wSocks)) {
            // ensure we don't run the CPU too high
            usleep((int) $timeout);
            return;
        }

        if (
            @!stream_select($rSocks, $wSocks, $eSocks, $timeout !== null ? 0 : null, (int) $timeout)
        ) {
            return;
        }

        if ($eSocks) {
            foreach ($eSocks as $id => $socket) {
                $wSocks[$id] = $socket;
            }
        }

        foreach ($rSocks as $socket) {
            $id = get_resource_id($socket);

            foreach ($rTasks[$fd] as $idx => $task) {
                if (!$task->isPersistent()) {
                    unset($rTasks[$id][$idx]);
                }

                $this->schedule($task->isPersistent() ? $task->spawn(false) : $task);
            }

            $this->readSockets[$fdMap[$fd]] = $rTasks[$fd];
        }

        foreach ($wSocks as $socket) {
            $id = get_resource_id($socket);

            foreach ($wTasks[$id] as $idx => $task) {
                if (!$task->isPersistent()) {
                    unset($wTasks[$fd][$idx]);
                }

                $this->schedule($task->isPersistent() ? $task->spawn(false) : $task);
            }

            $this->writeSockets[$fdMap[$fd]] = $wTasks[$fd];
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
            count($this->readSockets) === 0 &&
            count($this->writeSockets) === 0 &&
            $this->tasks === 0 &&
            count($this->timers, COUNT_RECURSIVE) === 0
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
