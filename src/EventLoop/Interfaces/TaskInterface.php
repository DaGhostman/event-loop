<?php
namespace Onion\Framework\EventLoop\Interfaces;

interface TaskInterface
{
    public function run();

    public function throw(\Throwable $ex): void;
    public function finished(): bool;
    public function started(): bool;
}
