<?php
namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Interfaces\TaskInterface;

class Task implements TaskInterface
{
    protected $taskId;
    protected $coroutine;

    protected $suspended = false;
    protected $killed = false;

    public function __construct($taskId, Coroutine $coroutine)
    {
        $this->taskId = $taskId;
        $this->coroutine = $coroutine;
    }

    public function getId(): int
    {
        return $this->taskId;
    }

    public function run()
    {
        return $this->coroutine->run();
    }

    public function suspend(): bool
    {
        if ($this->isKilled()) {
            return false;
        }

        $this->suspended = true;
        return true;
    }

    public function resume(): bool
    {
        if ($this->isKilled()) {
            return false;
        }

        $this->suspended = false;
        return true;
    }

    public function throw(\Throwable $ex): void
    {
        $this->coroutine->throw($ex);
    }

    public function send($value): void
    {
        $this->coroutine->send($value);
    }

    public function push($data): void
    {
        $this->coroutine->getChannel()->push($data);
    }

    public function pop()
    {
        return $this->coroutine->getChannel()->pop();
    }

    public function isChannelEmpty()
    {
        return $this->coroutine->getChannel()->isEmpty();
    }

    public function kill(): void
    {
        $this->killed = true;
    }

    public function isKilled(): bool
    {
        return $this->killed;
    }

    public function isFinished(): bool
    {
        return ($this->isKilled() || !$this->coroutine->valid());
    }

    public function isPaused(): bool
    {
        return $this->suspended;
    }
}
