<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler\Interfaces;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Closure;

interface NetworkedSchedulerInterface extends SchedulerInterface
{
    public function listen(string $address, int $port, Closure $dispatchFunction): string;
    // public function bind(): void;

    // public function read(ResourceInterface $socket): string;
    // public function write(ResourceInterface $socket, string $data): int;
}
