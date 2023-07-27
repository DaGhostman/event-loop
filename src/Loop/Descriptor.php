<?php

declare(strict_types=1);

namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Interfaces\{ResourceInterface, SchedulerInterface, TaskInterface};
use Onion\Framework\Loop\Types\Operation;

use function Onion\Framework\Loop\signal;

class Descriptor implements ResourceInterface
{
    /** @var mixed */
    private mixed $resource;
    private readonly int $resourceId;

    public function __construct(mixed $resource)
    {
        $this->resourceId = is_object($resource) ?
            spl_object_id($resource) : get_resource_id($resource);
        $this->resource = $resource;
    }

    public function read(int $size): string|false
    {
        if (!is_readable($this)) {
            return false;
        }

        return fread($this->getResource(), $size);
    }

    public function write(string $data): int|false
    {
        if (!is_writeable($this)) {
            return false;
        }

        return fwrite($this->getResource(), $data);
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

    public function getResource(): mixed
    {
        return $this->resource;
    }

    public function block(): bool
    {
        if (!$this->isAlive()) {
            return false;
        }

        return stream_set_blocking($this->getResource(), true);
    }

    public function unblock(): bool
    {
        if (!$this->isAlive()) {
            return false;
        }

        return stream_set_blocking($this->getResource(), false);
    }

    public function lock(int $lockType = LOCK_NB | LOCK_SH): bool
    {
        return flock($this->resource, $lockType);
    }

    public function unlock(): bool
    {
        return flock($this->resource, LOCK_UN | LOCK_NB);
    }

    public function detach(): mixed
    {
        $resource = $this->resource;

        $this->resource = null;

        return $resource;
    }
}
