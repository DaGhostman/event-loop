<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;

use Onion\Framework\Loop\Interfaces\{ResourceInterface, SchedulerInterface, TaskInterface};
use Onion\Framework\Loop\Scheduler\Interfaces\NetworkClientAwareSchedulerInterface;
use Onion\Framework\Loop\Scheduler\Interfaces\NetworkServerAwareSchedulerInterface;
use Onion\Framework\Loop\Scheduler\Traits\SchedulerErrorHandler;
use Onion\Framework\Loop\Scheduler\Traits\StreamNetworkUtil;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use SplQueue;
use Throwable;

class Select implements SchedulerInterface, NetworkServerAwareSchedulerInterface, NetworkClientAwareSchedulerInterface
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

            if ($task->isKilled()) {
                continue;
            }

            if ($task->isPersistent()) {
                // Return the parent task back to the queue for rescheduling
                $this->schedule($task);
                // Spawn a new task and schedule it for execution
                $task = $task->spawn();
                // Swap the parent with the child task for execution
                $this->schedule($task);
            }

            try {
                $result = $task->run();

                if ($result instanceof Signal) {
                    $this->queue->enqueue(
                        Task::create(\Closure::fromCallable($result), [$task, $this])
                    );
                    continue;
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

        $timeout *= 1000;

        if (
            (empty($rSocks) && empty($wSocks)) ||
            @!stream_select($rSocks, $wSocks, $eSocks, $timeout !== null ? 10 : null, $timeout)
        ) {
            return;
        }

        foreach ($rSocks as $socket) {
            $id = get_resource_id($socket);
            [, $tasks] = $this->reads[$id];

            foreach ($tasks as $idx => $task) {
                if ($task->isKilled()) {
                    continue;
                }

                $this->schedule($task->isPersistent() ? $task->spawn() : $task);
            }
        }

        foreach ($wSocks as $socket) {
            $id = get_resource_id($socket);
            [, $tasks] = $this->writes[$id];

            foreach ($tasks as $idx => $task) {
                if ($task->isKilled()) {
                    unset($this->writes[$id][1][$idx]);
                    continue;
                }

                $this->schedule($task->isPersistent() ? $task->spawn() : $task);
            }
        }
    }

    protected function timerPoll(int $now): void
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
        if (count($this->timers) > 0 && $isEmpty) {
            $diff = min(array_keys($this->timers)) - $tick;
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
