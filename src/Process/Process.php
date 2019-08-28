<?php
namespace Onion\Framework\Process;

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\read;
use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\AsyncResourceInterface;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Traits\AsyncResourceTrait;

class Process extends Descriptor implements AsyncResourceInterface
{
    private $input;
    private $output;
    private $err;

    private $exitCode = -1;

    use AsyncResourceTrait;

    private function __construct(
        $resource,
        ResourceInterface $input,
        ResourceInterface $output,
        ResourceInterface $error = null
    ) {
        parent::__construct($resource);

        $this->input = $input;
        $this->output = $output;
        $this->err = $error;
    }

    public static function exec(string $command, array $args, array $env = [], ?string $cwd = null): Process
    {
        $args = array_map('escapeshellarg', $args);
        array_unshift($args, $command);

        $proc = proc_open(implode(' ', $args), [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w+'],
        ], $pipes, $cwd ?? getcwd(), array_merge(getenv(), $env));

        return new Process(
            $proc,
            new Descriptor($pipes[0]),
            new Descriptor($pipes[1]),
            new Descriptor($pipes[2])
        );
    }

    public function getPid(): int
    {
        return (int) $this->getStatus()['pid'];
    }

    public function isTerminated(): bool
    {
        return (bool) !$this->getStatus()['running'];
    }

    public function isRunning()
    {
        return $this->isAlive() && !$this->isTerminated();
    }

    public function getStatus()
    {
        $status = proc_get_status($this->getDescriptor());
        if ($status['exitcode'] !== -1 && $this->exitCode === -1) {
            $this->exitCode = $status['exitcode'];
        }
        return $status;
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

    public function wait(int $operation = self::OPERATION_READ)
    {
        if ($operation === static::OPERATION_READ) {
            return $this->output->wait($operation);
        }

        if ($operation === static::OPERATION_WRITE) {
            return $this->input->wait($operation);
        }

        throw new \InvalidArgumentException("Invalid operation ({$operation}) to wait");
    }

    public function close(): bool
    {
        return $this->input->close() &&
            $this->output->close() &&
            ($this->exitCode = proc_close($this->getDescriptor()));
    }

    public function kill(int $signal = 15): bool
    {
        return proc_terminate($this->getDescriptor(), $signal);
    }

    public function isAlive(): bool
    {
        return !$this->isTerminated();
    }

    public function onError(callable $callback): void
    {
        coroutine(function (ResourceInterface $stream, callable $callback) {
            if ($stream->isAlive()) {
                return;
            }

            yield read($stream, function(ResourceInterface $stream) use (&$callback) {
                yield call_user_func($callback, $stream);

                $this->onError($callback);
            });
        }, [$this->err, $callback]);
    }
}
