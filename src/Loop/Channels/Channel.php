<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Channels;

use Closure;
use Onion\Framework\Loop\Channels\ChannelValue;
use Onion\Framework\Loop\Interfaces\Channels\ChannelInterface;
use Onion\Framework\Loop\Interfaces\Channels\ChannelValueInterface;
use SplQueue;

use function Onion\Framework\Loop\signal;

class Channel implements ChannelInterface
{
    private bool $open = true;
    private readonly SplQueue $queue;
    private readonly SplQueue $waiting;

    public function __construct()
    {
        $this->waiting = new SplQueue();
        $this->queue = new SplQueue();
    }

    public function close(): void
    {
        $this->open = false;

        while (!$this->waiting->isEmpty()) {
            $this->waiting->dequeue()(null, false);
        }
    }

    public function recv(): ChannelValueInterface
    {
        return signal(function (Closure $resume) {
            if ($this->queue->isEmpty()) {
                if (!$this->open) {
                    $resume(new ChannelValue(null, $this->open));
                } else {
                    $this->waiting->enqueue(function (mixed $item) use ($resume) {
                        $resume(new ChannelValue($item, $this->open));
                    });
                }
            } else {
                $resume(
                    !$this->open && $this->queue->isEmpty() ?
                        new ChannelValue(null, false) :
                        new ChannelValue($this->queue->dequeue(), $this->open || !$this->queue->isEmpty())
                );
            }
        });
    }

    public function send(mixed ...$data): bool
    {
        return signal(function (Closure $resume) use ($data) {
            if (!$this->open) {
                $resume(false);
            }

            while (!$this->waiting->isEmpty() && !empty($data)) {
                foreach ($data as $idx => $value) {
                    $this->waiting->dequeue()($value);
                    unset($data[$idx]);
                }
            }

            foreach ($data as $value) {
                $this->queue->enqueue($value);
            }

            $resume(false);
        });
    }
}
