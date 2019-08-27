<?php
namespace Onion\Framework\Loop\Traits;

trait LockableResourceTrait
{
    public function lock(int $lockType = LOCK_NB | LOCK_SH): bool
    {
        if (!stream_supports_lock($this->getDescriptor())) {
            return true;
        }

        return flock($this->getDescriptor(), $lockType);
    }

    public function unlock(): bool
    {
        if (!stream_supports_lock($this->getDescriptor())) {
            return true;
        }

        return flock($this->getDescriptor(), LOCK_UN | LOCK_NB);
    }
}
