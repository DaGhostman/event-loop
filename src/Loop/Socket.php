<?php

namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\{
    ResourceInterface,
};

class Socket extends Descriptor
{
    public function __construct(mixed $resource, private ?string $address = null)
    {
        parent::__construct(
            $resource instanceof ResourceInterface ? $resource->getResource() : $resource
        );
    }

    public function read(int $size, int $flags = 0): string
    {
        $response = stream_socket_recvfrom(
            $this->getResource(),
            $size,
            $flags,
            $peer,
        );

        if (!isset($this->address) && $peer !== null) {
            $this->address = $peer;
        }

        return $response;
    }

    public function write(string $data, int $flags = 0): int
    {
        return stream_socket_sendto(
            $this->getResource(),
            $data,
            $flags,
            $this->address,
        );
    }

    public function getName(bool $remote = false): string|false
    {
        return stream_socket_get_name(
            $this->getResource(),
            $remote,
        );
    }

    public function negotiateCrypto(
        bool $enable = true,
        int $method = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
    ): bool | int {
        return stream_socket_enable_crypto(
            $this->getResource(),
            $enable,
            $method,
        );
    }
}
