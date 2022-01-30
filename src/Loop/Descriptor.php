<?php

namespace Onion\Framework\Loop;

use function Onion\Framework\Loop\signal;

use Onion\Framework\Loop\Exceptions\BadStreamOperation;
use Onion\Framework\Loop\Exceptions\DeadStreamException;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;

use Onion\Framework\Loop\Types\Operation;

class Descriptor implements ResourceInterface
{
    /** @var resource */
    private mixed $resource;
    private readonly int $resourceId;

    public function __construct(mixed $resource)
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

    public function read(int $size, int $flags = 0): string
    {
        if (!is_readable($this)) {
            throw new BadStreamOperation('read');
        }

        return  stream_is_local($this->getResource()) ?
            fread($this->getResource(), $size) :
            stream_socket_recvfrom(
                $this->getResource(),
                $size,
                $flags,
            );
    }

    public function write(string $data, int $flags = 0): int
    {
        if (!is_writeable($this)) {
            throw new BadStreamOperation('write');
        }

        return stream_is_local($this->getResource()) ?
            fwrite($this->getResource(), $data) :
            stream_socket_sendto(
                $this->getResource(),
                $data,
                $flags,
                stream_socket_get_name($this->getResource(), true),
            );
    }

    public function close(): bool
    {
        if ($this->resource && is_resource($this->resource)) {
            if (!stream_is_local($this->resource)) {
                stream_socket_shutdown(
                    $this->getResource(),
                    STREAM_SHUT_RDWR,
                );
            }

            return fclose($this->resource);
        }

        return true;
    }

    public function eof(): bool
    {
        return !$this->getResource() ||
            !is_resource($this->getResource()) ||
            feof($this->getResource());
    }

    public function isAlive(): bool
    {
        return !$this->eof();
    }

    public function getResourceId(): int
    {
        return $this->resourceId;
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function block(): bool
    {
        if (!$this->isAlive()) {
            throw new \LogicException('block');
        }

        return stream_set_blocking($this->getResource(), true);
    }

    public function unblock(): bool
    {
        if (!$this->isAlive()) {
            throw new DeadStreamException('unblock');
        }

        return stream_set_blocking($this->getResource(), false);
    }

    public function wait(Operation $operation = Operation::READ): mixed
    {
        return signal(function (
            callable $resume,
            TaskInterface $task,
            SchedulerInterface $scheduler
        ) use ($operation) {
            $task->resume();
            switch ($operation) {
                case Operation::READ:
                    $scheduler->onRead($this, $task);
                    break;
                case Operation::WRITE:
                    $scheduler->onWrite($this, $task);
                    break;
                case Operation::ERROR:
                    $scheduler->onError($this, $task);
                    break;
            }
        });
    }

    public function lock(int $lockType = LOCK_NB | LOCK_SH): bool
    {
        return flock($this->resource, $lockType);
    }

    public function unlock(): bool
    {
        return flock($this->resource, LOCK_UN | LOCK_NB);
    }
}
