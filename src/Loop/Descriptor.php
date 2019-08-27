<?php
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Interfaces\AsyncResourceInterface;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Traits\AsyncResourceTrait;

class Descriptor implements AsyncResourceInterface
{
    private $resource;
    private $resourceId;

    use AsyncResourceTrait;

    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException(sprintf(
                'Expected argument to be resource, got %s instead.',
                gettype($resource)
            ));
        }

        $this->resourceId = (int) $resource;
        $this->resource = $resource;
    }

    public function read(int $size): string
    {
        return fread($this->resource, $size);
    }

    public function write(string $data): int
    {
        return fwrite($this->resource, $data);
    }

    public function close(): bool
    {
        if ($this->isAlive()) {
            stream_socket_shutdown($this->resource, STREAM_SHUT_RDWR);
        }

        return fclose($this->resource);
    }

    public function isAlive(): bool
    {
        return $this->resource &&
            is_resource($this->resource) &&
            !feof($this->resource);
    }

    public function getDescriptorId(): int
    {
        return $this->resourceId;
    }

    public function getDescriptor()
    {
        return $this->resource;
    }
}
