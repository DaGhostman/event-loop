<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;

class Ev implements SchedulerInterface
{
    private readonly mixed $loop;

    private readonly \SplQueue $tasks;

    public function __construct()
    {
        $this->loop = new \EvLoop();
        $this->tasks = new \SplQueue();
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
	public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($at !== null) {
            $this->tasks->enqueue($this->loop->timer(
                ($at - (hrtime(true) / 1e3)) / 1e6,
                0,
                function ($watcher) {
                    $this->tasks->dequeue();

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
            ));
        } else {
            $this->tasks->enqueue($this->loop->idle(
                function ($watcher) {
                    $this->tasks->dequeue();
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
            ));
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
        $this->tasks->enqueue($this->loop->io(
            $resource->getResource(),
            \Ev::READ,
            function ($watcher) {
                $this->tasks->dequeue();

                $this->schedule($watcher->data);
            },
            $task
        ));
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
	public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        $this->tasks->enqueue($this->loop->io(
            $resource->getResource(),
            \Ev::WRITE,
            function ($watcher) {
                $this->tasks->dequeue();

                $this->schedule($watcher->data);
            },
            $task
        ));
	}

	/**
	 * Starts the event loop execution cycle. All code written after
	 * a call to this method will be executed as soon as there are no
	 * scheduled tasks, timers and watched resources
	 * @return void
	 */
	public function start(): void
    {
        $this->loop->run();
	}

	/**
	 * Stops the event loop after completing the current tick
	 */
	public function stop(): void
    {
        $this->loop->stop(Ev::BREAK_ALL);
	}
}
