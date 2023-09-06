<?php
declare(strict_types=1);
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\{
    ResourceInterface,
};

use function Onion\Framework\Loop\suspend;

class Socket extends Descriptor
{
    private bool $secure = false;

    public function __construct(mixed $resource, private ?string $address = null)
    {
        $resource = $resource instanceof ResourceInterface ? $resource->getResource() : $resource;
        $this->secure = isset(stream_context_get_options($resource)['ssl']);

        parent::__construct($resource);
    }

    public function read(int $size, int $flags = 0): false|string
    {
        $peer = null;
        $response = $this->secure ? parent::read($size) : @stream_socket_recvfrom(
            $this->getResource(),
            $size,
            $flags,
            $peer,
        );

        if (!isset($this->address) && $peer !== null) {
            $this->address = $peer;
        } elseif (!isset($this->address) && $peer === null) {
            $this->address = $this->getName(true) ?: null;
        }

        return $response;
    }

    public function write(string $data, int $flags = 0): int
    {
        return $this->secure ? parent::write($data) : @stream_socket_sendto(
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

    public function negotiateSecurity(
        int $method,
        mixed $seed = null,
    ): bool | int {
        if (!$this->secure) {
            return false;
        }

        $negotiation = 0;
        while ($negotiation === 0) {
            $negotiation = stream_socket_enable_crypto(
                $this->getResource(),
                true,
                $method,
                $seed,
            );

            // allow loop to continue and enough data to be available
            suspend();
        }

        if ($negotiation === false) {
            throw new \RuntimeException('Failed to negotiate security');
        }

        $this->secure = true;

        return $negotiation;
    }
}
