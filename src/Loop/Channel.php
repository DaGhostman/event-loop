<?php
namespace Onion\Framework\Loop;

class Channel
{
    /** @var \SplQueue */
    private $queue;
    private $open = true;

    public function __construct()
    {
        $this->queue = new \SplQueue;
    }

    public function send($value)
    {
        yield $this->queue->enqueue($value);
    }

    public function recv()
    {
        while ($this->queue->isEmpty() && $this->open) {
            yield;
        }

        if ($this->open) {
            return $this->queue->dequeue();
        }
    }

    public function isEmpty()
    {
        return $this->queue->isEmpty();
    }

    public function close()
    {
        $this->open = false;
    }
}
