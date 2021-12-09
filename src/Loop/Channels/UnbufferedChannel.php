<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Channels;

use function Onion\Framework\Loop\tick;

class UnbufferedChannel extends AbstractChannel
{
    private bool $sender = false;
    private bool $receiver = false;

    public function send(mixed $value): void
    {
        $this->sender = true;
        while (!$this->receiver || ($this->receiver = count($this) !== 0)) {
            tick();
        }
        parent::send($value);
        $this->sender = false;
    }

    public function recv(): mixed
    {
        $this->receiver = true;
        while (!$this->sender || ($this->sender && count($this) === 0)) {
            tick();
        }

        $value = parent::recv();
        $this->receiver = false;

        return $value;
    }
}
