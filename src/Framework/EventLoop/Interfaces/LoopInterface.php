<?php
namespace Onion\Framework\EventLoop\Interfaces;

interface LoopInterface
{
    const TASK_IMMEDIATE = 0;
    const TASK_DEFERRED = 1;

    public function start(): void;
    public function push(TaskInterface $task, int $type = self::TASK_IMMEDIATE): TaskInterface;
    public function stop(): void;
    public function kill(): void;
}
