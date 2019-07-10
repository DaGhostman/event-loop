<?php
namespace Onion\Framework\Loop\Interfaces;

use Onion\Framework\Loop\Channel;

interface TaskInterface
{
    public function getId(): int;

    public function run();
    public function suspend(): bool;
    public function resume(): bool;
    public function kill(): void;

    public function getChannel(): Channel;

    public function send($value): void;
    public function throw(\Throwable $exception): void;

    public function isFinished(): bool;
    public function isPaused(): bool;
}
