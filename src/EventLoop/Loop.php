<?php
namespace Onion\Framework\EventLoop;

class Loop
{
    private $queue;
    private $deferred;

    private $streams = [];

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

    public function interval(int $interval, \Closure $callback)
    {
        $generator = function () use ($callback) {
            for (;;) {
                yield $callback();
            }
        };

        $timer = new Timer(
            $generator(),
            $interval,
            Timer::TYPE_INTERVAL
        );

        $this->queue->enqueue($timer);

        return $timer;
    }

    public function delay(int $interval, \Closure $callback)
    {
        $generator = function () use ($callback) {
            for (;;) {
                yield $callback();
            }
        };

        $timer = new Timer(
            $generator(),
            $interval,
            Timer::TYPE_DELAY
        );

        $this->queue->enqueue($timer);

        return $timer;
    }

    public function push(\Closure $callback)
    {
        $generator = function () use ($callback) {
            yield $callback();
        };

        $this->queue->enqueue(new Task($generator()));
    }

    public function defer(\Closure $callback)
    {
        $generator = function () use ($callback) {
            yield $callback();
        };

        $this->deferred->enqueue(new Task($generator()));
    }

    public function io(Descriptor $poll)
    {
        $this->queue->enqueue($poll);
    }

    private function run(\SplQueue $queue)
    {
        while(!$queue->isEmpty()) {
            /** @var Task $task */
            $task = $queue->dequeue();

            try {
                $task->run();
            } catch (\Throwable $ex) {
                var_dump($ex);
                $task->throw($ex);
                continue;
            }

            if (!$task->finished()) {
                $queue->enqueue($task);
            }
        }
    }
}
