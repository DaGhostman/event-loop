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
use SplQueue;


class Event implements SchedulerInterface
{
    private readonly EventBase $base;
    private readonly SplQueue $tasks;
    public function __construct()
    {
        $config = new EventConfig();
        $config->requireFeatures(EventConfig::FEATURE_FDS);
		$config->avoidMethod("select");
        $config->requireFeatures(EventConfig::FEATURE_ET);
        // $config->requireFeatures(EventConfig::FEATURE_O1);

        $this->base = new EventBase($config);
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
	public function schedule(TaskInterface $task, int $at = null): void
    {
		if ($this->base->gotStop()) {
			return;
		}

        $event = new TaskEvent($this->base, -1, TaskEvent::TIMEOUT, function ($fd, $what, TaskInterface $task) {
			$this->tasks->dequeue()->free();
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

        }, $task);
        $event->add($at !== null ? ($at - (hrtime(true) / 1e+3)) / 1e+6 : 0);
        $this->tasks->enqueue($event);
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
		$event = new TaskEvent($this->base, $resource->getResource(), TaskEvent::READ, function ($fd, $what, TaskInterface $task) {
			$this->tasks->dequeue()->free();
			$this->schedule($task);
		}, $task);
		$event->add();
		$this->tasks->enqueue($event);
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
		$event = new TaskEvent($this->base, $resource->getResource(), TaskEvent::WRITE, function ($fd, $what, TaskInterface $task) {
			$this->tasks->dequeue()->free();
			$this->schedule($task);
		}, $task);
		$event->add();
		$this->tasks->enqueue($event);
	}

	/**
	 * Starts the event loop execution cycle. All code written after
	 * a call to this method will be executed as soon as there are no
	 * scheduled tasks, timers and watched resources
	 * @return void
	 */
	public function start(): void {
		$this->base->loop();
	}

	/**
	 * Stops the event loop after completing the current tick
	 */
	public function stop(): void {
		$this->base->stop();
	}
}
