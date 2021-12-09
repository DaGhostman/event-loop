<?php

namespace Onion\Framework\Process;

use Closure;

use Onion\Framework\Loop\Types\Operation;
use Onion\Framework\Loop\{
    Descriptor,
    Interfaces\ResourceInterface,
};

class Process extends Descriptor implements ResourceInterface
{
    private readonly ResourceInterface $input;
    private readonly ResourceInterface $output;
    private readonly ResourceInterface $err;

    private readonly \Closure $restartCallback;

    private $exitCode = -1;

    private function __construct(
        $resource,
        ResourceInterface $input,
        ResourceInterface $output,
        ResourceInterface $error,
        callable $restartCallback = null
    ) {
        parent::__construct($resource);

        $this->input = $input;
        $this->output = $output;
        $this->err = $error;

        $this->restartCallback = Closure::fromCallable($restartCallback ?? function (): void {
            throw new \RuntimeException('Process cannot be restarted');
        });
    }

    public static function exec(string $command, array $args = [], array $env = [], ?string $cwd = null): Process
    {
        $args = array_map('escapeshellarg', $args);
        array_unshift($args, $command);

        $factory = function () use ($args, $env, $cwd, &$factory): self {
            $proc = proc_open(implode(' ', $args), [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ], $pipes, $cwd ?? getcwd(), array_merge(getenv(), $env), [
                'blocking_pipes' => false,
            ]);

            return new Process(
                $proc,
                new Descriptor($pipes[0]),
                new Descriptor($pipes[1]),
                new Descriptor($pipes[2]),
                $factory
            );
        };

        return $factory();
    }

    public function getPid(): int
    {
        return $this->getStatus()['pid'];
    }

    public function isTerminated(): bool
    {
        return !$this->getStatus()['running'];
    }

    public function isRunning(): bool
    {
        return $this->isAlive();
    }

    public function getStatus(): array
    {
        $status = $this->getResource() ? proc_get_status($this->getResource()) : false;
        if (is_array($status) && $status['exitcode'] !== -1 && $this->exitCode === -1) {
            $this->exitCode = $status['exitcode'];
        }
        return $status ?: [
            'pid' => -1,
            'running' => false,
        ];
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function read(int $size): string
    {
        return $this->output->read($size);
    }

    public function write(string $data): int
    {
        return $this->input->write($data);
    }

    public function unblock(): bool
    {
        return $this->input->unblock() &&
            $this->output->unblock() &&
            $this->err->unblock();
    }

    public function block(): bool
    {
        return $this->input->block() &&
            $this->output->block() &&
            $this->err->block();
    }

    public function wait(Operation $operation = Operation::READ)
    {
        return match ($operation) {
            Operation::READ => $this->output->wait(Operation::READ),
            Operation::WRITE => $this->input->wait(Operation::WRITE),
            Operation::ERROR => $this->err->wait(Operation::ERROR),
        };
    }

    public function close(): bool
    {
        $this->input->close();
        $this->output->close();
        $this->err->close();

        if (is_resource($this->getResource())) {
            ($this->exitCode = proc_close($this->getResource()));
        }

        return true;
    }

    public function kill(int $signal = null): bool
    {
        if (is_resource($this->getResource()))
            proc_terminate($this->getResource(), $signal ?? 15);

        return $this->close();
    }

    public function isAlive(): bool
    {
        return !$this->isTerminated();
    }

    public function restart(): Process
    {
        return ($this->restartCallback)();
    }

    public function __debugInfo()
    {
        return proc_get_status($this->getResource());
    }
}
