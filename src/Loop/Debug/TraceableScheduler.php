<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Debug;
use Closure;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;

class TraceableScheduler implements SchedulerInterface
{
    private array $stack = [];
    private Closure $statCollector;

    public function __construct(
        private readonly SchedulerInterface $scheduler,
    )
    {
        $this->statCollector = fn () => null;
    }

	public function schedule(TaskInterface $task, int $at = null): void
    {
        $this->scheduler->schedule($task, $at);
	}

	public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        $this->scheduler->onRead($resource, $task);
	}

	public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        $this->scheduler->onWrite($resource, $task);
	}

	public function signal(int $signal, TaskInterface $task): void
    {
        $this->scheduler->signal($signal, $task);
	}

	public function start(): void
    {
        $this->scheduler->start();
	}

	public function stop(): void
    {
        $this->scheduler->start();
	}

    public function onError(callable $handler): void
    {
        $this->scheduler->onError($handler);
    }

    public function stat(array $trace, $stats): void
    {
        $this->stack[] = [
            $trace,
            $stats,
        ];
    }

    public function setStatCollector(Closure $fn): void
    {
        $this->statCollector = $fn;
    }

    public function __destruct()
    {
        ($this->statCollector)($this->stack);
    }
}
