<?php
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SocketInterface;

class Socket extends Descriptor implements SocketInterface
{
    public function read(int $size, int $flags = 0): string
    {
        return stream_socket_recvfrom(
            $this->getDescriptor(),
            $size,
            $flags
        );
    }

    public function write(string $data, int $flags = 0): int
    {
        return stream_socket_sendto(
            $this->getDescriptor(),
            $data,
            $flags,
            stream_socket_get_name($this->getDescriptor(), true)
        );
    }

    public function accept(?int $timeout = 0): ResourceInterface
    {
        return new Descriptor(@stream_socket_accept($this->getDescriptor(), $timeout));
    }
}
