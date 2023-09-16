<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;

use Closure;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Resources\Buffer;
use Onion\Framework\Loop\Resources\CallbackStream;
use Onion\Framework\Loop\Scheduler\Traits\SchedulerErrorHandler;
use Onion\Framework\Loop\Scheduler\Traits\StreamNetworkUtil;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use Onion\Framework\Loop\Types\NetworkAddress;
use Onion\Framework\Loop\Types\NetworkProtocol;
use Onion\Framework\Server\Interfaces\ContextInterface as ServerContext;
use Onion\Framework\Client\Interfaces\ContextInterface as ClientContext;
use Throwable;

use function Onion\Framework\Loop\signal;

class Uv implements SchedulerInterface
{
    use SchedulerErrorHandler;
    use StreamNetworkUtil {
        open as private nativeOpen;
        connect as private nativeConnect;
    }

    private readonly mixed $loop;
    private bool $running = false;
    private bool $stopped = false;
    private \WeakMap $readers;
    private \WeakMap $writers;

    public function __construct()
    {
        // handle different libuv versions
        $this->loop = function_exists('uv_loop_init') ? uv_loop_init() : uv_loop_new();
        $this->readers = new \WeakMap();
        $this->writers = new \WeakMap();
    }

    public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($this->stopped) {
            return;
        }

        if ($at === null) {
            uv_idle_start(uv_idle_init($this->loop), function ($handle) use ($task, $at) {
                uv_idle_stop($handle);
                uv_close($handle);

                if ($task->isKilled() || $task->isFinished()) {
                    return;
                }

                try {
                    $result = $task->run();

                    if ($result instanceof Signal) {
                        $this->schedule(Task::create(Closure::fromCallable($result), [$task, $this]));
                        return;
                    }

                    if (
                        !$task->isKilled() &&
                        $task->isFinished() &&
                        $task->isPersistent()
                    ) {
                        $this->schedule($task->spawn());
                    }

                    $this->schedule($task, $at);
                } catch (Throwable $e) {
                    $this->triggerErrorHandlers($e);
                }
            });
        } else {
            uv_timer_start(
                uv_timer_init($this->loop),
                (int) ($at !== null ? ($at - (hrtime(true) / 1e3)) / 1e3 : 0),
                0,
                function ($handle) use ($task, $at) {
                    // uv_timer_stop($handle);
                    uv_close($handle);

                    if ($task->isKilled() || $task->isFinished()) {
                        return;
                    }

                    try {
                        $result = $task->run();

                        if ($result instanceof Signal) {
                            $this->schedule(Task::create(Closure::fromCallable($result), [$task, $this]));
                            return;
                        }

                        if (
                            !$task->isKilled() &&
                            $task->isFinished() &&
                            $task->isPersistent()
                        ) {
                            $this->schedule($task->spawn());
                        }

                        $this->schedule($task, $at);
                    } catch (Throwable $e) {
                        if (!$task->throw($e)) {
                            $this->triggerErrorHandlers($e);
                        }
                    }
                }
            );
        }
    }

    private function poll(ResourceInterface $resource): void
    {
        // $id = $resource->getResourceId();

        $poll = @uv_poll_init_socket($this->loop, $resource->getResource());

        if (!$poll) {
            @uv_fs_read($this->loop, $resource->getResource(), 0, 1, function ($raw, $result) use ($resource) {
                if (!isset($this->readers[$resource])) {
                    return;
                }

                if ($result !== '' && $result < 0) {
                    // An error occurred and we cleanup
                    if (isset($this->readers[$resource])) {
                        unset($this->readers[$resource]);
                    }

                    return;
                }

                $tasks = $this->readers[$resource] ?? [];
                foreach($tasks as $idx => $task) {
                    $this->schedule($task->spawn(false));

                    if (!$task->isPersistent()) {
                        unset($tasks[$idx]);
                    }
                }
                $this->readers[$resource] = $tasks;

                if (!empty($this->readers[$resource])) {
                    $this->schedule(Task::create($this->poll(...), [$resource]));
                    return;
                }
            });

            @uv_fs_write($this->loop, $resource->getResource(), '', -1, function ($raw, $result) use ($resource) {
                if (!isset($this->writers[$resource])) {
                    return;
                }

                if ($result !== '' && $result < 0) {
                    if (isset($this->writers[$resource])) {
                        unset($this->writers[$resource]);
                    }

                    return;
                }

                $tasks = $this->writers[$resource] ?? [];
                foreach($tasks as $idx => $task) {
                    $this->schedule($task->spawn(false));

                    if (!$task->isPersistent()) {
                        unset($tasks[$idx]);
                    }
                }

                $this->writers[$resource] = $tasks;

                if (!empty($tasks)) {
                    $this->schedule(Task::create($this->poll(...), [$resource]));
                    return;
                }
            });

            return;
        }

        @uv_poll_start(
            $poll,
            \UV::READABLE | \UV::WRITABLE,
            function ($poll, $stat, $ev) use ($resource) {
                if ($stat === \UV::EOF) {
                    uv_poll_stop($poll);
                    unset($this->readers[$resource], $this->writers[$resource]);
                    uv_close($poll);
                    return;
                }

                if (($ev & \UV::READABLE) === \UV::READABLE) {
                    $tasks = $this->readers[$resource] ?? [];
                    foreach ($tasks as $idx => $task) {
                        if (!$task->isPersistent()) {
                            unset($tasks[$idx]);
                        }


                        $this->schedule($task->spawn(false));
                    }

                    $this->readers[$resource] = $tasks;

                    if (empty($tasks)) {
                        unset($this->readers[$resource]);
                    }
                }

                if (($ev & \UV::WRITABLE) === \UV::WRITABLE) {
                    $tasks = $this->writers[$resource] ?? [];
                    foreach ($tasks as $idx => $task) {
                        if (!$task->isPersistent()) {
                            unset($tasks[$idx]);
                        }


                        $this->schedule($task->spawn(false));
                    }

                    $this->writers[$resource] = $tasks;

                    if (empty($tasks)) {
                        unset($this->writers[$resource]);
                    }
                }

                if (empty($this->readers[$resource]) && empty($this->writers[$resource])) {
                    uv_poll_stop($poll);
                    uv_close($poll, function () use ($resource) {
                        unset($this->readers[$resource], $this->writers[$resource]);
                    });
                }
            }
        );
    }

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

        if ($resource->eof()) {
            return;
        }

        if (!isset($this->readers[$resource])) {
            $this->readers[$resource] = [];
        }

        $this->readers[$resource][] = $task;
        $this->schedule(Task::create($this->poll(...), [$resource]));
    }

    public function onWrite(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

        if ($resource->eof()) {
            return;
        }

        if (!isset($this->writers[$resource])) {
            $this->writers[$resource] = [];
        }

        $this->writers[$resource][] = $task;
        $this->schedule(Task::create($this->poll(...), [$resource]));
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
        uv_signal_start(uv_signal_init($this->loop), function ($handle) use ($task) {
            $this->schedule($task);
            uv_close($handle);
        }, match ($signal) {
            defined('PHP_WINDOWS_EVENT_CTRL_C') ? PHP_WINDOWS_EVENT_CTRL_C : -1,
                defined('PHP_WINDOWS_EVENT_CTRL_BREAK') ? PHP_WINDOWS_EVENT_CTRL_BREAK : -1 => \UV::SIGINT,
            default => $signal,
        });
    }

    public function open(
        string $address,
        int $port,
        Closure $callback,
        NetworkProtocol $protocol = NetworkProtocol::TCP,
        ServerContext $context = null,
        NetworkAddress $type = NetworkAddress::NETWORK
    ): string {
        if ($context !== null || ($type === NetworkAddress::LOCAL && $protocol === NetworkProtocol::UDP)) {
            return $this->nativeOpen($address, $port, $callback, $protocol, $context, $type);
        }

        if ($type === NetworkAddress::NETWORK) {
            /** @var \UVSockAddr $addr */
            $addr = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ? uv_ip6_addr($address, $port)
                : uv_ip4_addr($address, $port);

            return match ($protocol) {
                NetworkProtocol::TCP => $this->listen(
                    $this->createTcpNetworkSocket($addr),
                    function (\UVTcp $socket): \UVTcp {
                        /** @var \UVTcp $accept */
                        $accept = uv_tcp_init($this->loop);
                        uv_accept($socket, $accept);

                        return $accept;
                    },
                    $callback
                ),
                NetworkProtocol::UDP => $this->bind(
                    $this->createUdpNetworkSocket($addr),
                    $callback,
                ),
            };
        }

        if ($type === NetworkAddress::LOCAL) {
            return $this->listen(
                $this->createPipeSocket($address),
                function (\UVPipe $socket): \UVPipe {
                    /** @var \UVPipe $accept */
                    $accept = uv_pipe_init($this->loop, false);
                    uv_accept($socket, $accept);

                    return $accept;
                },
                $callback
            );
        };

        throw new \InvalidArgumentException("Invalid address type provided");
    }

    private function createTcpNetworkSocket(\UVSockAddr $address = null): \UVTcp
    {
        /** @var \UVTcp $socket */
        $socket = uv_tcp_init($this->loop);
        if ($address !== null) {
            uv_tcp_bind($socket, $address);
        }

        uv_tcp_nodelay($socket, true);
        uv_tcp_simultaneous_accepts($socket, true);

        return $socket;
    }

    private function createUdpNetworkSocket(\UVSockAddr $address = null): \UVUdp
    {
        /** @var \UVUdp $socket */
        $socket = uv_udp_init($this->loop);
        if ($address !== null) {
            uv_udp_bind($socket, $address);
        }

        return $socket;
    }

    private function createPipeSocket(string $address): \UVPipe
    {
        /** @var \UVPipe $socket */
        $socket = uv_pipe_init($this->loop, false);
        uv_pipe_bind($socket, $address);

        return $socket;
    }

    private function listen(
        \UVTcp | \UVPipe $sock,
        Closure $acceptFunction,
        Closure $dispatchFunction,
    ): string {
        uv_listen($sock, -1, function ($server) use ($dispatchFunction, $acceptFunction) {
            $client = $acceptFunction($server);
            $buff = new Buffer();

            $this->schedule(Task::create($dispatchFunction, [new CallbackStream(
                $buff->read(...),
                static fn () => !uv_is_active($client) && $buff->eof(),
                static fn (string $data) => signal(static fn ($resume) => uv_is_active($client) ? uv_write(
                    $client,
                    $data,
                    static fn (\UVTcp $sock, int $status) => $resume($status === 0 ? strlen($data) : false)
                ) : $resume(false)),
                static fn () => !uv_is_closing($client) ? uv_close($client) : null,
                $buff->getResource(),
                $buff->getResourceId(),
            )]));

            uv_read_start($client, fn (
                \UVTcp | \UVPipe $client,
                ?int $nbRead,
                ?string $buffer = null,
            ) => match ($nbRead) {
                \UV::EOF => $buff->close() && uv_close($client),
                default => $buff->write((string) $buffer),
            });
        });

        /** @var array $server */
        $server = uv_tcp_getsockname($sock);

        return "{$server['address']}:{$server['port']}";
    }

    private function bind(
        \UVUdp $sock,
        Closure $dispatchFunction
    ): string {
        uv_udp_recv_start($sock, function (
            \UVUdp $client,
            string|int|null $nreadOrBuffer,
            ?string $buffer = null,
        ) use ($dispatchFunction) {
            // handle previous versions of ext-libuv signature
            if (!is_int($nreadOrBuffer)) {
                $buffer = $nreadOrBuffer;
            }

            [$ip, $port] = uv_udp_getsockname($client);
            $addr = uv_ip4_addr($ip, $port);
            if (preg_match('/::/', $ip)) {
                $addr = uv_ip6_addr($ip, $port);
            }

            $buff = new Buffer();
            $buff->write((string) $buffer);

            $this->schedule(Task::create($dispatchFunction, [
                new CallbackStream(
                    static fn (int $size) => signal(static fn (Closure $resume) => $resume($buff->read($size))),
                    static fn () => !uv_is_active($client),
                    static fn (string $data) => signal(static fn ($resume) => uv_udp_send(
                        $client,
                        $data,
                        $addr,
                        static fn (\UVUdp $r, int $status) => $resume($status === 0 ? strlen($data) : false)
                    )),
                    static fn () => uv_udp_recv_stop($client),
                    $buff->getResource(),
                    $buff->getResourceId(),
                )
            ]));
        });

        $bind =  uv_udp_getsockname($sock);

        return "{$bind['address']}:{$bind['port']}";
    }

    public function connect(
        string $address,
        int $port,
        Closure $callback,
        NetworkProtocol $protocol = NetworkProtocol::TCP,
        ?ClientContext $context = null,
        NetworkAddress $type = NetworkAddress::NETWORK,
    ): void {
        if ($context !== null || ($type === NetworkAddress::LOCAL && $protocol === NetworkProtocol::UDP)) {
            $this->nativeConnect($address, $port, $callback, $protocol, $context, $type);

            return;
        }

        if ($type === NetworkAddress::NETWORK) {
            /** @var \UVSockAddr $addr */
            $addr = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ? uv_ip6_addr($address, $port)
                : uv_ip4_addr($address, $port);


            match ($protocol) {
                NetworkProtocol::TCP => uv_tcp_connect(
                    uv_tcp_init($this->loop),
                    $addr,
                    function ($socket, $static) use ($callback) {
                        $buff = new Buffer();

                        $this->schedule(Task::create($callback, [new CallbackStream(
                            static fn (int $size) => signal(fn ($resume) => $resume($buff->read($size))),
                            static fn () => !uv_is_active($socket) && $buff->eof(),
                            static fn (string $data) => signal(static fn ($resume) => !uv_is_closing($socket) ? uv_write(
                                $socket,
                                $data,
                                static fn (\UVTcp $sock, int $status) => $resume($status === 0 ? strlen($data) : false)
                            ) : $resume(false)),
                            static fn () => !uv_is_closing($socket) ? uv_close($socket) : null,
                            $buff->getResource(),
                            $buff->getResourceId(),
                        )]));

                        uv_read_start(
                            $socket,
                            static function ($socket, $status, $buffer) use ($buff) {
                                match ($status) {
                                    \UV::EOF => uv_close($socket),
                                    default => $buff->write((string) $buffer),
                                };
                            },
                        );
                    },
                ),
                NetworkProtocol::UDP => $this->send($callback, $addr),
            };
        } elseif ($type === NetworkAddress::LOCAL) {
            uv_pipe_connect(
                uv_pipe_init($this->loop, false),
                $address,
                function ($socket, $static) use ($callback) {
                    $buff = new Buffer();

                    $this->schedule(Task::create($callback, [new CallbackStream(
                        static fn (int $size) => signal(static fn ($resume) => $resume($buff->read($size))),
                        static fn () => $buff->size() > 0 ? $buff->eof() : false,
                        static fn (string $data)  => signal(static fn ($resume) => uv_write(
                            $socket,
                            $data,
                            static fn (\UVPipe $sock, int $status) => $resume($status === 0 ? strlen($data) : false)
                        )),
                        static fn () => uv_close($socket),
                        $buff->getResource(),
                        $buff->getResourceId(),
                    )]));

                    uv_read_start(
                        $socket,
                        static fn ($socket, $status, $data) => match ($status) {
                            \UV::EOF => uv_close($socket),
                            default => $buff->write((string) $data),
                        },
                    );
                }
            );
        } else {
            throw new \InvalidArgumentException("Invalid address type provided");
        }
    }

    public function send(
        Closure $callback,
        \UVSockAddr $addr = null,
    ): void {
        $buff = new Buffer();

        /** @var \UVUdp $socket */
        $socket = uv_udp_init($this->loop);

        $this->schedule(Task::create($callback, [new CallbackStream(
            static fn (int $size) => signal(static fn (Closure $resume) => $resume($buff->read($size))),
            static fn () => $buff->size() > 0 ? $buff->eof() : false,
            static fn (string $data) => signal(static fn ($resume) => uv_udp_send(
                $socket,
                $data,
                $addr,
                static fn (\UVUdp $r, int $status) => $resume($status === 0 ? strlen($data) : false)
            )),
            static fn () => uv_close($socket),
        )]));

        uv_udp_recv_start($socket, static fn ($socket, $nread, $buffer) => match ($nread) {
            \UV::EOF => uv_close($socket),
            default => $buff->write((string) $buffer),
        });
    }

    public function __debugInfo()
    {
        return [
            'r' => count($this->readers),
            'w' => count($this->writers),
            'mem' => memory_get_usage(),
        ];
    }
}
