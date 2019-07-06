<?php
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Descriptor;

class Channel
{
    private const DATA_MARKER = "\x01";

    /** @var \SplQueue */
    private $queue;

    /** @var Descriptor $resource*/
    private $resource;

    public function __construct()
    {
        $this->resource = new Descriptor(fopen('php://temp/maxmemory=8192', 'rb+'));
        $this->queue = new \SplQueue;
    }

    public function push($value): void
    {
        $this->queue->enqueue($value);
        $this->resource->write(static::DATA_MARKER);
    }

    public function pop()
    {
        $this->resource->read(strlen(static::DATA_MARKER));
        return !$this->queue->isEmpty() ? $this->queue->dequeue() : null;
    }

    public function isEmpty()
    {
        return $this->queue->isEmpty();
    }
}
