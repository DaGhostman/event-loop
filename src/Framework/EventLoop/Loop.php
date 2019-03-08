<?php
namespace Onion\Framework\EventLoop;

use Closure;
use Countable;
use Onion\Framework\EventLoop\Interfaces\LoopInterface;
use Onion\Framework\EventLoop\Interfaces\TaskInterface;
use Onion\Framework\EventLoop\Stream\Stream;
use Onion\Framework\EventLoop\Task\Timer;

class Loop implements Countable, LoopInterface
{
    private $readStreams = [];
    private $writeStreams = [];
    private $readListeners = [];
    private $writeListeners = [];

    private $timers;
    private $queue;
    private $deferred;

    private $stopped = false;

    public function __construct()
    {
        $this->queue = new \SplQueue();
        $this->timers = new \SplQueue();
        $this->deferred = new \SplQueue();

        $this->queue->setIteratorMode(\SplQueue::IT_MODE_DELETE);
        $this->timers->setIteratorMode(\SplQueue::IT_MODE_DELETE);
        $this->deferred->setIteratorMode(\SplQueue::IT_MODE_DELETE);
    }

    public function start(): void
    {
        while (!$this->stopped) {
            array_map(function ($stream) {
                if (!is_resource($stream)) {
                    $this->detach($stream);
                }
            }, $this->readStreams);

            array_map(function ($stream) {
                if (!is_resource($stream)) {
                    $this->detach($stream);
                }
            }, $this->writeStreams);

            if (!empty($this->readStreams) || !empty($this->writeStreams)) {
                $reads = $this->readStreams;
                $writes = $this->writeStreams;
                $errors = [];

                if (@select($reads, $writes, $errors, 0) !== false) {
                    foreach ($reads as $read) {
                        $fd = (int) $read;

                        $socket = new Stream($read);
                        ($this->readListeners[$fd])($socket);
                    }

                    foreach ($writes as $write) {
                        $fd = (int) $write;

                        $socket = new Stream($write);
                        ($this->writeListeners[$fd])($socket);
                    }
                }
            }

            try { // Protect excessive loops by checking count
                $this->tick($this->timers);
                $this->tick($this->queue);
            } finally {
                $this->tick($this->deferred); // We have to run all deferred
            }
        }
    }

    public function attach($resource, ?Closure $onRead = null, ?Closure $onWrite = null): bool
    {
        $fd = (int) $resource;

        if ($onRead === null && $onWrite === null) {
            return false;
        }

        if (!isset($this->streams[$fd])) {
            if ($onRead !== null) {
                $this->readStreams[$fd] = $resource;
                $this->readListeners[$fd] = $onRead;
            }

            if ($onWrite !== null) {
                $this->writeStreams[$fd] = $resource;
                $this->writeListeners[$fd] = $onWrite;
            }
        }

        return true;
    }

    public function detach($resource): bool
    {
        $fd = (int) $resource;

        if (!isset($this->readStreams[$fd]) && !isset($this->writeStreams)) {
            return false;
        }

        if (isset($this->readStreams[$fd])) {
            unset($this->readStreams[$fd]);
            unset($this->readListeners[$fd]);
        }

        if (isset($this->writeStreams[$fd])) {
            unset($this->writeStreams[$fd]);
            unset($this->writeListeners[$fd]);
        }

        return true;
    }

    public function push(TaskInterface $task, int $type = self::TASK_IMMEDIATE): TaskInterface
    {
        if ($task instanceof Timer) {
            $this->timers->enqueue($task);
        } else {
            /** @var \SplQueue $queue */
            $queue = ($type === self::TASK_IMMEDIATE) ?
                $this->queue : $this->deferred;

            $queue->enqueue($task);
        }

        return $task;
    }

    private function tick(\SplQueue $queue)
    {
        $max = count($queue);
        while(!$queue->isEmpty() && $max--) {
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

    public function count()
    {
        return count($this->queue) && count($this->deferred);
    }
}
