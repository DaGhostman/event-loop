<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Channels;

use function Onion\Framework\Loop\signal;
use function Onion\Framework\Loop\tick;

class UnbufferedChannel extends AbstractChannel
{
    private bool $receiver = false;

    public function send(mixed $value): void
    {
        while (!$this->receiver || ($this->receiver = count($this) !== 0)) {
            tick();
        }
        parent::send($value);
    }

    public function recv(): ChannelValue
    {
        $this->receiver = true;
        while (count($this) === 0 && !$this->isClosed()) {
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
            $this->receiver = false;
        });
    }
}
