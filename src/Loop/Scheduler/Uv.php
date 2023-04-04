<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use SplQueue;

class Uv implements SchedulerInterface
{
    private readonly mixed $loop;
    private readonly SplQueue $tasks;

    public function __construct()
    {
        $this->loop = uv_loop_new();
        $this->tasks = new SplQueue();
    }

	/**
	 * Schedule a task for execution either during at the earliest tick
	 * or at a given time if the $at parameter is provided.
	 *
	 * @param \Onion\Framework\Loop\Interfaces\TaskInterface $task The task to put on the queue
	 * @param int|null $at Time at which to execute the given task (in
	 *                     microseconds)
	 * @return void
	 */
	public function schedule(\Onion\Framework\Loop\Interfaces\TaskInterface $task, int $at = null): void
    {
        if ($at === null) {
            $async = uv_async_init($this->loop, function($handle) use ($task, $at) {
                $this->tasks->dequeue();
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
            uv_async_send($async);
            $this->tasks->enqueue($async);
        } else {
            uv_timer_start(uv_timer_init($this->loop),(int) ($at !== null ? ($at - (hrtime(true) / 1e3)) / 1e3 : 0), 0, function($handle) use ($task, $at) {
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
        }
	}

	/**
	 * Schedules a task to be executed as soon as there is any data
	 * available to read from the given $resource.
	 *
	 * @param \Onion\Framework\Loop\Interfaces\ResourceInterface $resource The resource to await
	 * @param \Onion\Framework\Loop\Interfaces\TaskInterface $task The task to execute when data is available
	 * @return void
	 */
	public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        $poll = uv_poll_init($this->loop, $resource->getResource());
        uv_poll_start($poll, \UV::READABLE, function($poll, $stat, $ev) use ($task) {
            $this->schedule($task);
            uv_poll_stop($poll);
        });
	}

	/**
	 * Schedules a task to be executed as soon as the $resource is ready
	 * to receive data
	 *
	 * @param \Onion\Framework\Loop\Interfaces\ResourceInterface $resource The resource to await
	 * @param \Onion\Framework\Loop\Interfaces\TaskInterface $task The task to execute when data can be
	 *                                                             transmitted
	 * @return void
	 */
	public function onWrite(\Onion\Framework\Loop\Interfaces\ResourceInterface $resource, \Onion\Framework\Loop\Interfaces\TaskInterface $task): void
    {
        $poll = uv_poll_init($this->loop, $resource->getResource());
        uv_poll_start($poll, \UV::WRITABLE, function($poll, $stat, $ev) use ($task) {
            $this->schedule($task);
            uv_poll_stop($poll);
        });
	}

	/**
	 * Starts the event loop execution cycle. All code written after
	 * a call to this method will be executed as soon as there are no
	 * scheduled tasks, timers and watched resources
	 * @return void
	 */
	public function start(): void
    {
        uv_run($this->loop);
	}

	/**
	 * Stops the event loop after completing the current tick
	 */
	public function stop(): void
    {
        uv_stop($this->loop);
	}
}
