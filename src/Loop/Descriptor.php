<?php
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;

class Descriptor implements ResourceInterface
{
    private $resource;
    private $resourceId;

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
        return stream_socket_shutdown($this->resource, STREAM_SHUT_RDWR) &&
            fclose($this->resource);
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

    public function lock(int $lockType = LOCK_NB | LOCK_SH): bool
    {
        if (!stream_supports_lock($this->resource)) {
            return true;
        }

        return flock($this->resource, $lockType);
    }

    public function unlock(): bool
    {
        if (!stream_supports_lock($this->resource)) {
            return true;
        }

        return flock($this->resource, LOCK_UN | LOCK_NB);
    }

    public function block(): bool
    {
        return stream_set_blocking($this->resource, true);
    }

    public function unblock(): bool
    {
        return stream_set_blocking($this->resource, false);
    }

    public function getDescriptor()
    {
        return $this->resource;
    }

    public function wait(int $operation = self::OPERATION_READ)
    {
        return new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($operation) {
            if (($operation & static::OPERATION_READ) === static::OPERATION_READ) {
                $scheduler->onRead($this, $task);
            }

            if (($operation & static::OPERATION_WRITE) === static::OPERATION_WRITE) {
                $scheduler->onWrite($this, $task);
            }
        });
    }
}
