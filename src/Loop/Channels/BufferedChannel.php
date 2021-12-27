<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Channels;

use function Onion\Framework\Loop\signal;
use function Onion\Framework\Loop\tick;

class BufferedChannel extends AbstractChannel
{
    public function __construct(private int $capacity)
    {
        parent::__construct();
    }

    public function send(mixed $value): void
    {
        while (count($this) >= $this->capacity && !$this->isClosed()) {
            tick();
        }

        if (!$this->isClosed()) {
            parent::send($value);
        }
    }

    public function recv(): ChannelValue
    {
        while (!$this->isClosed() && count($this) === 0) {
            tick();
        }

        return signal(function ($resume) {
            $count = count($this);

            $resume(
                new ChannelValue(
                    count($this) !== 0 ? parent::recv() : null,
                    !$this->isClosed() ||
                        $this->isClosed() && $count !== 0
                ),
            );
        });
    }
}
