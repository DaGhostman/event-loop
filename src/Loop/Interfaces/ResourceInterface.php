<?php
namespace Onion\Framework\Loop\Interfaces;

interface ResourceInterface
{
    public function read(int $size): string;
    public function write(string $data): int;
    public function close(): bool;

    public function getDescriptor();
    public function getDescriptorId(): int;

    public function isAlive(): bool;
}
