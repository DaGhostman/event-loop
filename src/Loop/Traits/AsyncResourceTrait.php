<?php
namespace Onion\Framework\Loop\Traits;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;

trait AsyncResourceTrait
{
    public function block(): bool
    {
        if (!$this->isAlive()) {
            throw new \LogicException('Unable to block dead stream');
        }

        return stream_set_blocking($this->getDescriptor(), true);
    }

    public function unblock(): bool
    {
        if (!$this->isAlive()) {
            throw new \LogicException('Unable to unblock dead stream');
        }

        return stream_set_blocking($this->getDescriptor(), false);
    }

    public function wait(int $operation = self::OPERATION_READ)
    {
        if (!$this->isAlive()) {
            throw new \LogicException('Unable to wait dead stream');
        }

        /** @var ResourceInterface $self */
        $self = $this;
        return new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($operation, $self) {
            if (($operation & static::OPERATION_READ) === static::OPERATION_READ) {
                $scheduler->onRead($self, $task);
            }

            if (($operation & static::OPERATION_WRITE) === static::OPERATION_WRITE) {
                $scheduler->onWrite($self, $task);
            }
        });
    }
}
