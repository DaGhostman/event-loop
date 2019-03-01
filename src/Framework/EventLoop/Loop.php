<?php
namespace Onion\Framework\EventLoop;

use Countable;
use Onion\Framework\EventLoop\Interfaces\LoopInterface;
use Onion\Framework\EventLoop\Interfaces\TaskInterface;


class Loop implements Countable, LoopInterface
{
    private $queue;
    private $deferred;

    private $stopped = false;

    public function __construct()
    {
        $this->queue = new \SplQueue();
        $this->deferred = new \SplQueue();
        $this->queue->setIteratorMode(\SplQueue::IT_MODE_DELETE);
        $this->deferred->setIteratorMode(\SplQueue::IT_MODE_DELETE);
    }

    public function start(): void
    {
        try {
            $this->run($this->queue);
        } finally {
            $this->run($this->deferred);
        }
    }

    public function push(TaskInterface $task, int $type = self::TASK_IMMEDIATE): TaskInterface
    {
        /** @var \SplQueue $queue */
        $queue = ($type === self::TASK_IMMEDIATE) ?
            $this->queue : $this->deferred;

        $queue->enqueue($task);

        return $task;
    }

    private function run(\SplQueue $queue)
    {
        while(!$queue->isEmpty()) {
            /** @var Task $task */
            $task = $queue->dequeue();

            try {
                $task->run();
            } catch (\Throwable $ex) {
                $task->throw($ex);
            }

            if (!$task->finished() && !$this->stopped) {
                $queue->enqueue($task);
            }
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function kill(): void
    {
        while (!$this->queue->isEmpty()) {
            $this->queue->dequeue();
        }
    }

    public function count()
    {
        return count($this->queue) && count($this->deferred);
    }
}
