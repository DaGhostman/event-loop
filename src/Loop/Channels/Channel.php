<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Channels;

use Closure;
use Onion\Framework\Loop\Channels\ChannelValue;
use Onion\Framework\Loop\Interfaces\Channels\ChannelInterface;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use RuntimeException;
use SplQueue;
use Onion\Framework\Loop\Descriptor;

use function Onion\Framework\Loop\read;
use function Onion\Framework\Loop\signal;
use function Onion\Framework\Loop\write;

class Channel implements ChannelInterface
{
    private readonly SplQueue $queue;
    private bool $open = true;

    private readonly ResourceInterface $readableStream;
    private readonly ResourceInterface $writableStream;

    public function __construct()
    {
        $streams = stream_socket_pair(
            STREAM_PF_INET,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_TCP
        );

        if (!$streams) {
            throw new RuntimeException(
                'Unable to initialize channel streams'
            );
        }

        $this->readableStream = new Descriptor($streams[0]);
        $this->writableStream = new Descriptor($streams[1]);
    }

    public function close(): void
    {
        $this->open = false;
        $this->readableStream->close();
        $this->writableStream->close();
    }

    public function recv(): ChannelValue
    {
        return signal(function (Closure $resume) {
            read($this->readableStream, function (ResourceInterface $resource) use ($resume) {
                $resource->read(2);

                $resume(
                    !$this->open && $this->queue->isEmpty() ?
                        new ChannelValue(null, false) :
                        new ChannelValue($this->queue->dequeue(), $this->open || !$this->queue->isEmpty())
                );
            });
        });
    }

    public function send(mixed $value): bool
    {
        if ($this->open) {
            write($this->writableStream, function (ResourceInterface $resource) use (&$value) {
                $resource->write('.');

                $this->queue->enqueue($value);
            });

            return true;
        }

        return false;
    }
}
