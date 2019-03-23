<?php
namespace Onion\Framework\EventLoop\Interfaces;

use Guzzle\Stream\StreamInterface;

interface LoopInterface
{
    const TASK_IMMEDIATE = 0;
    const TASK_DEFERRED = 1;

    public function attach(StreamInterface $resource, ?callable $onRead = null, ?callable $onWrite = null): bool;
    public function detach(StreamInterface $resource): bool;

    public function start(): void;
    public function tick(): void;
    public function push(TaskInterface $task, int $type = self::TASK_IMMEDIATE): TaskInterface;
    public function stop(): void;
}
