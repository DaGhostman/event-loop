<?php
namespace Onion\Framework\Loop\Interfaces;

interface ResourceInterface
{
    const OPERATION_READ = 1;
    const OPERATION_WRITE = 2;

    public function read(int $size): string;
    public function write(string $data): int;
    public function close(): bool;
    public function lock(int $lockType = LOCK_NB | LOCK_SH): bool;
    public function unlock(): bool;
    public function block(): bool;
    public function unblock(): bool;

    public function getDescriptor();
    public function getDescriptorId(): int;

    public function isAlive(): bool;

    public function wait(int $operation = self::OPERATION_READ);
}
