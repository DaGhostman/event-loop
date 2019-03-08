<?php
namespace Onion\Framework\EventLoop\Stream\Interfaces;

interface StreamInterface
{
    public function read(int $size = 8192): ?string;
    public function write(string $contents): ?int;

    public function detach();
    public function close(): bool;

    public function block(): bool;
    public function unblock(): bool;
}
