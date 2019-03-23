<?php
namespace Onion\Framework\EventLoop;

use Countable;
use GuzzleHttp\Stream\StreamInterface;
use Onion\Framework\EventLoop\Interfaces\LoopInterface;
use Onion\Framework\EventLoop\Interfaces\TaskInterface;
use Onion\Framework\EventLoop\Task\Timer;

class Loop implements Countable, LoopInterface
{
    public const BROADCAST_READ = 1;
    public const BROADCAST_WRITE = 2;
    public const BROADCAST_ALL = self::BROADCAST_READ | self::BROADCAST_WRITE;

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
            array_map(function (StreamInterface $stream) {
                if ($stream->eof()) {
                    $this->detach($stream);
                }

            }, $this->readStreams);

            array_map(function ($stream) {
                if ($stream->eof()) {
                    $this->detach($stream);
                }
            }, $this->writeStreams);

            if (!empty($this->readStreams) || !empty($this->writeStreams)) {
                $reads = array_map(function (StreamInterface $stream) {
                    $pointer = $stream->detach();
                    $stream->attach($pointer);

                    return $pointer;
                }, $this->readStreams);

                $writes = array_map(function (StreamInterface $stream) {
                    $pointer = $stream->detach();
                    $stream->attach($pointer);

                    return $pointer;
                }, $this->writeStreams);
                $errors = [];

                if (@select($reads, $writes, $errors, count($this) > 0 ? 0 : null) !== false) {
                    foreach ($reads as $read) {
                        if (!is_resource($read) || feof($read)) {
                            $this->detach($read);
                            continue;
                        }

                        $fd = (int) $read;
                        call_user_func($this->readListeners[$fd], $this->readStreams[$fd]);
                    }

                    foreach ($writes as $write) {
                        if (!is_resource($write) || feof($write)) {
                            $this->detach($write);
                            continue;
                        }

                        $fd = (int) $write;
                        call_user_func($this->writeListeners[$fd], $this->writeStreams[$fd]);
                    }
                }
            }

            $this->tick();
        }
    }

    public function tick(): void
    {
        try {
            $this->run($this->timers);
            $this->run($this->queue);
        } finally {
            $this->run($this->deferred);
        }
    }

    public function attach(StreamInterface $resource, ?callable $onRead = null, ?callable $onWrite = null): bool
    {
        $pointer = $resource->detach();
        $resource->attach($pointer);
        $fd = (int) $pointer;

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

    public function detach(StreamInterface $resource): bool
    {
        $pointer = $resource->detach();
        $resource->attach($pointer);
        $fd = (int) $pointer;

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

    private function run(\SplQueue $queue)
    {
        $max = count($queue); // Protect excessive loops by checking count
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
        return count($this->queue) +
            count($this->deferred) +
            count($this->timers);
    }

    public function broadcast(callable $callback, int $type = self::BROADCAST_ALL): void
    {
        if (($type & self::BROADCAST_READ) === self::BROADCAST_READ) {
            foreach ($this->readStreams as $stream) {
                $callback($stream);
            }
        }

        if (($type & self::BROADCAST_WRITE) === self::BROADCAST_WRITE) {
            foreach ($this->readStreams as $stream) {
                $callback($stream);
            }
        }
    }
}
