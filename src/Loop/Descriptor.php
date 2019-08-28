<?php
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Interfaces\AsyncResourceInterface;
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
        if (!is_readable($this)) {
            throw new \LogicException("Reading from non-readable stream");
        }

        return fread($this->getDescriptor(), $size);
    }

    public function write(string $data): int
    {
        if (!is_writeable($this)) {
            throw new \LogicException("Writing to non-writable stream");
        }
        return fwrite($this->getDescriptor(), $data);
    }

    public function close(): bool
    {
        if ($this->isAlive()) {
            stream_socket_shutdown($this->getDescriptor(), STREAM_SHUT_RDWR);
        }

        return fclose($this->getDescriptor());
    }

    public function isAlive(): bool
    {
        return $this->getDescriptor() &&
            is_resource($this->getDescriptor()) &&
            !feof($this->getDescriptor());
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
