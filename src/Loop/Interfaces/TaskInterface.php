<?php
namespace Onion\Framework\Loop\Interfaces;

interface TaskInterface
{
    public function getId(): int;

    public function run();
    public function suspend(): bool;
    public function resume(): bool;
    public function kill(): void;

    public function send($value): void;
    public function throw(\Throwable $exception): void;

    public function isFinished(): bool;
    public function isPaused(): bool;
}
