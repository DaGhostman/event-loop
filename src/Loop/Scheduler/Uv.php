<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Scheduler\Interfaces\NetworkedSchedulerInterface;
use Onion\Framework\Loop\Scheduler\Traits\SchedulerErrorHandler;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use Throwable;

class Uv implements SchedulerInterface, NetworkedSchedulerInterface
{
    private readonly mixed $loop;
    private bool $running = false;
    private bool $stopped = false;

    use SchedulerErrorHandler;

    public function __construct()
    {
        $this->loop = uv_loop_init();
    }

    public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($this->stopped) {
            return;
        }

        if ($at === null) {
            uv_idle_start(uv_idle_init($this->loop), function($handle) use ($task, $at) {
                uv_close($handle);

                if ($task->isKilled()) {
                    return;
                }

                try {
                    $result = $task->run();

                    if ($result instanceof Signal) {
                        try {
                            $this->schedule(Task::create($result, [$task, $this]));
                        } catch (Throwable $ex) {
                            if (!$task->throw($ex)) {
                                $this->triggerErrorHandlers($ex);
                            }
                        }
                        return;
                    }
                } catch (Throwable $e) {
                    $this->triggerErrorHandlers($e);
                }

                if (!$task->isFinished()) {
                    $this->schedule($task, $at);
                }
            });
        } else {
            uv_timer_start(
                uv_timer_init($this->loop),
                (int) ($at !== null ? ($at - (hrtime(true) / 1e3)) / 1e3 : 0),
                0,
                function($handle) use ($task, $at) {
                    uv_close($handle);

                    if ($task->isKilled()) {
                        return;
                    }
                    $result = $task->run();

                    if ($result instanceof Signal) {
                        $this->schedule(Task::create($result, [$task, $this]));
                        return;
                    }

                    if (!$task->isFinished()) {
                        $this->schedule($task, $at);
                    }
                }
            );
        }
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->eof()) {
            return;
        }

        uv_poll_start(
            uv_poll_init($this->loop, $resource->getResource()),
            \UV::READABLE,
            function($poll, $stat, $ev) use ($task) {
                uv_poll_stop($poll);
                $this->schedule($task);
            }
        );
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->eof()) {
            return;
        }

        uv_poll_start(
            uv_poll_init($this->loop, $resource->getResource()),
            \UV::WRITABLE,
            function($poll, $stat, $ev) use ($task) {
                uv_poll_stop($poll);

                $this->schedule($task);
            }
        );
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        uv_run($this->loop);
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }
        $this->stopped = true;

        uv_stop($this->loop);
    }

    public function signal(int $signal, TaskInterface $task): void
    {
        uv_signal_start(uv_signal_init($this->loop), function($handle) use ($task) {
            $this->schedule($task);
            uv_close($handle);
        }, $signal);
    }

    public function work(TaskInterface $task): void
    {
        $this->schedule($task);
    }

    public function listen(string $address, int $port, \Closure $dispatchFunction): string
    {
        $sock = uv_tcp_init($this->loop);

        uv_tcp_bind(
            $sock,
            preg_match('/^\d+\.\d+\.\d+\.\d+$/', $address) === 1 ?
                uv_ip4_addr($address, $port) : uv_ip6_addr($address, $port)
        );

        uv_tcp_simultaneous_accepts($sock, true);
        uv_listen($sock, 1000, function($server) use ($dispatchFunction) {
            $client = uv_tcp_init();
            uv_accept($server, $client);

            uv_read_start($client, function($socket, $nread, $buffer) use ($dispatchFunction, $server) {
                uv_read_stop($socket);

                if ($buffer !== null && uv_) {
                    uv_write($socket, $dispatchFunction($buffer), function($socket, $status) use ($dispatchFunction, $server) {
                        uv_close($socket);
                        if ($status < 0) {
                            uv_close($server);
                            return;
                        }
                    });
                } else {
                    uv_close($socket);
                }
            });
        });

        $server = uv_tcp_getsockname($sock);

        return "{$server['address']}:{$server['port']}";
    }
}
