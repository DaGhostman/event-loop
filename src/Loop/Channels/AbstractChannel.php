<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Channels;

use Countable;

abstract class AbstractChannel implements Countable
{
    private \SplQueue $queue;
    private bool $open = true;

    public function __construct()
    {
        $this->queue = new \SplQueue();
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function send(mixed $value): void
    {
        if (!$this->isClosed()) {
            $this->queue->enqueue($value);
        }
    }

    public function recv(): ChannelValue
    {
        return new ChannelValue(
            $this->queue->dequeue(),
            !$this->queue->isEmpty()
        );
    }

    public function count(): int
    {
        return $this->queue->count();
    }

    public function isClosed(): bool
    {
        return !$this->open;
    }
}
