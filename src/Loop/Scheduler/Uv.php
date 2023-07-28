<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler;

use Closure;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Resources\Buffer;
use Onion\Framework\Loop\Resources\CallbackStream;
use Onion\Framework\Loop\Scheduler\Interfaces\NetworkClientAwareSchedulerInterface;
use Onion\Framework\Loop\Scheduler\Interfaces\NetworkServerAwareSchedulerInterface;
use Onion\Framework\Loop\Scheduler\Traits\SchedulerErrorHandler;
use Onion\Framework\Loop\Scheduler\Traits\StreamNetworkUtil;
use Onion\Framework\Loop\Scheduler\Types\NetworkAddressType;
use Onion\Framework\Loop\Scheduler\Types\NetworkProtocol;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use Onion\Framework\Loop\Types\Operation;
use Onion\Framework\Server\Interfaces\ContextInterface as ServerContext;
use Onion\Framework\Client\Interfaces\ContextInterface as ClientContext;
use Throwable;

use function Onion\Framework\Loop\signal;
use function Onion\Framework\Loop\suspend;
use function Onion\Framework\Loop\is_pending;

class Uv implements SchedulerInterface,
    NetworkServerAwareSchedulerInterface,
    NetworkClientAwareSchedulerInterface
{
    private readonly mixed $loop;
    private bool $running = false;
    private bool $stopped = false;

    private \WeakMap $streams;

    use SchedulerErrorHandler;
    use StreamNetworkUtil {
        open as private nativeOpen;
        connect as private nativeConnect;
    }

    public function __construct()
    {
        // handle different libuv versions
        $this->loop = function_exists('uv_loop_init') ? uv_loop_init() : uv_loop_new();
        $this->streams = new \WeakMap();
    }

    public function schedule(TaskInterface $task, int $at = null): void
    {
        if ($this->stopped) {
            return;
        }

        if ($at === null) {

            uv_idle_start(uv_idle_init($this->loop), function($handle) use ($task, $at) {
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
                function($handle) use ($task, $at) {
                    uv_timer_stop($handle);
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

    public function onRead(ResourceInterface $resource, TaskInterface $task): void
    {
        if ($resource->getResource() === null) {
            $this->schedule($task);
            return;
        }

        if ($resource->eof()) {
            return;
        }

        if (stream_is_local($resource->getResource())) {
            $this->schedule(Task::create(function () use ($resource, $task) {
                while (!is_pending($resource, Operation::READ)) {
                    suspend();
                }

                $this->schedule($task);
            }));
            return;
        }

        uv_poll_start(
            uv_poll_init($this->loop, $resource->getResource()),
            \UV::READABLE,
            function($poll, $stat, $ev) use ($task) {
                if (!$task->isPersistent()) {
                    uv_poll_stop($poll);
                }

                $this->schedule($task->spawn(false));
            }
        );
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

        if (stream_is_local($resource->getResource())) {
            $this->schedule(Task::create(function () use ($resource, $task) {
                while (!is_pending($resource, Operation::WRITE)) {
                    suspend();
                }
                $this->schedule($task);
            }));
            return;
        }

        uv_poll_start(
            uv_poll_init($this->loop, $resource->getResource()),
            \UV::WRITABLE,
            function($poll, $stat, $ev) use ($task) {
                if (!$task->isPersistent()) {
                    uv_poll_stop($poll);
                }

                $this->schedule($task->spawn(false));
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

    public function open(
        string $address,
        int $port,
        Closure $callback,
        NetworkProtocol $protocol = NetworkProtocol::TCP,
        ServerContext $context = null,
        NetworkAddressType $type = NetworkAddressType::NETWORK
    ): string {
        if ($context !== null || ($type === NetworkAddressType::LOCAL && $protocol === NetworkProtocol::UDP)) {
            return $this->nativeOpen($address, $port, $callback, $protocol, $context, $type);
        }

        if ($type === NetworkAddressType::NETWORK) {
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

        if ($type === NetworkAddressType::LOCAL) {
            return $this->listen(
                $this->createPipeSocket($address),
                function (\UVPipe $socket): \UVPipe {
                    /** @var \UVPipe $accept */
                    $accept = uv_pipe_init($this->loop);
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
        uv_listen($sock, -1, function($server) use ($dispatchFunction, $acceptFunction) {
            uv_read_start($acceptFunction($server), function(
                \UVTcp | \UVPipe $client,
                ?int $nbRead,
                ?string $buffer = null,
            ) use ($dispatchFunction) {
                if (!isset($this->streams[$client])) {
                    $this->streams[$client] = new Buffer();

                    $this->schedule(Task::create($dispatchFunction, [
                        new CallbackStream(
                            fn () => signal(fn (Closure $resume) => $resume($this->streams[$client]->read(...))),
                            fn () => $this->streams[$client]->size() > 0 ? $this->streams[$client]->eof() : false,
                            fn (string $data) => signal(fn (Closure $resume) => $resume(uv_write($client, $data, fn () => null))),
                            fn () => uv_read_stop($client),
                        )
                    ]));
                }

                $this->streams[$client]->write((string) $buffer);
            });
        });

        /** @var array $server */
        $server = uv_tcp_getsockname($sock);

        return "{$server['address']}:{$server['port']}";
    }

    private function bind(
        \UVUdp | \UVPipe $sock,
        Closure $dispatchFunction
    ): string {
        uv_udp_recv_start($sock, function(
            \UVUdp $client,
            string|int|null $nreadOrBuffer,
            ?string $buffer = null,
            \UVSockAddr $addr = null,
        ) use ($dispatchFunction) {
            if (!is_int($nreadOrBuffer)) {
                $buffer = $nreadOrBuffer;
            }

            if (!isset($this->streams[$client])) {
                $this->streams[$client] = new Buffer();

                $this->schedule(Task::create($dispatchFunction, [
                    new CallbackStream(
                        fn () => signal(fn (Closure $resume) => $resume($this->streams[$client]->read(...))),
                        fn () => $this->streams[$client]->size() > 0 ? $this->streams[$client]->eof() : false,
                        fn (string $data) => uv_udp_send($client, $data, $addr, fn () => null),
                        fn () => uv_udp_recv_stop($client)
                    )
                ]));
            }

            $this->streams[$client]->write((string) $buffer);
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
        NetworkAddressType $type = NetworkAddressType::NETWORK,
    ): void
    {
        if ($context !== null || ($type === NetworkAddressType::LOCAL && $protocol === NetworkProtocol::UDP)) {
            $this->nativeConnect($address, $port, $callback, $protocol, $context, $type);

            return;
        }

        if ($type === NetworkAddressType::NETWORK) {
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
                            fn (int $size, Closure $close) => signal(function (Closure $resume) use ($buff, $socket, $size, $close) {
                                if ($buff->size() > 0) {
                                    $resume($buff->read($size) ?? false);
                                    return;
                                }

                                $handler = set_error_handler(function () use ($resume) {
                                    $resume(false);

                                    return true;
                                });

                                uv_read_start(
                                    $socket,
                                    static function ($socket, $status, $buffer) use ($buff, $size, $resume, $close): void {
                                        if ($status === \UV::EOF) {
                                            $close();
                                        } else if ($status >= 0) {
                                            $buff->write((string) $buffer);
                                        }

                                        $resume($buff->read($size) ?? false);
                                    }
                                );
                                set_error_handler($handler);
                            }),
                            fn () => $buff->size() > 0 ? $buff->eof() : false,
                            function (string $data) use ($socket) {
                                return signal(fn ($resume) => uv_write($socket, $data, fn (\UVTcp $sock, int $status) => $resume($status === 0 ? strlen($data) : false)));
                            },
                            fn () => uv_close($socket),
                        )]));
                    },
                ),
                NetworkProtocol::UDP => $this->send($callback, $addr),
            };
        } else if ($type === NetworkAddressType::LOCAL) {
            uv_pipe_connect(
                uv_pipe_init($this->loop),
                $address,
                function ($socket, $static) use ($callback) {
                    $buff = new Buffer();

                    $this->schedule(Task::create($callback, [new CallbackStream(
                        fn (int $size, Closure $close) => signal(function (Closure $resume) use ($buff, $socket, $size, $close) {
                            if ($buff->size() > 0) {
                                $resume($buff->read($size) ?? false);
                                return;
                            }

                            $handler = set_error_handler(function () use ($resume) {
                                $resume(false);

                                return true;
                            });

                            uv_read_start(
                                $socket,
                                static function ($socket, $status, $buffer) use ($buff, $size, $resume, $close): void {
                                    if ($status === \UV::EOF) {
                                        $close();
                                    } else if ($status >= 0) {
                                        $buff->write((string) $buffer);
                                    }

                                    $resume($buff->read($size) ?? false);
                                }
                            );
                            set_error_handler($handler);
                        }),
                        fn () => $buff->size() > 0 ? $buff->eof() : false,
                        function (string $data) use ($socket) {
                            return signal(fn ($resume) => uv_write($socket, $data, fn (\UVTcp $sock, int $status) => $resume($status === 0 ? strlen($data) : false)));
                        },
                        fn () => uv_close($socket),
                    )]));
                }
            );
        } else {
            throw new \InvalidArgumentException("Invalid address type provided");
        }
    }

    public function send(
        Closure $callback,
        \UVSockAddr $addr = null,
    ): void
    {
        $buff = new Buffer();

        /** @var \UVUdp $socket */
        $socket = uv_udp_init($this->loop);

        $this->schedule(Task::create($callback, [new CallbackStream(
            fn () => signal(fn (Closure $resume) => $resume($buff->read(...))),
            fn () => $buff->size() > 0 ? $buff->eof(...) : false,
            fn (string $data) => signal(fn ($resume) => $resume(uv_udp_send($socket, $data, $addr))),
            fn () => uv_udp_recv_stop($socket),
        )]));



        uv_udp_recv_start($socket, fn ($socket, $nread, $buffer, $addr) => $buff->write((string) $buffer));
    }

    private function dispatch(\UVUdp|\UVTcp $socket, string $data, \UVSockAddr $address = null): void
    {
        switch(get_class($socket)) {
            case \UVUdp::class:
                uv_udp_send($socket, $data, $address);
                break;
            case \UVTcp::class:
                uv_write($socket, $data);
                break;
        }
    }
}
