<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler\Traits;

use Closure;
use Onion\Framework\Client\Interfaces\ContextInterface as ClientContext;
use \Onion\Framework\Loop\Types\NetworkAddress;
use \Onion\Framework\Loop\Types\NetworkProtocol;
use Onion\Framework\Loop\Resources\CallbackStream;
use Onion\Framework\Loop\Socket;
use Onion\Framework\Server\Interfaces\ContextInterface as ServerContext;
use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Task;

use function Onion\Framework\Loop\{suspend, buffer, signal};

trait StreamNetworkUtil
{
    protected function queue(Closure $cb, mixed ...$args): void
    {
        if (!$this instanceof SchedulerInterface) {
            throw new \LogicException(
                'Using StreamNetworkUtil trait in a class that does not '.
                    'implement SchedulerInterface is invalid'
            );
        }

        $this->schedule(Task::create($cb, $args));
    }

    protected function createServerSocket(
        string $address,
        int $port,
        NetworkProtocol $protocol,
        ?ServerContext $context,
        NetworkAddress $type
    ): ResourceInterface
    {
        $ctx = null;
        if ($context !== null) {
            $ctx = stream_context_create($context->getContextArray());
        }

        $socket = match ($protocol) {
            NetworkProtocol::TCP => match ($type) {
                NetworkAddress::NETWORK => stream_socket_server("tcp://{$address}:{$port}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx),
                NetworkAddress::LOCAL => stream_socket_server("unix://{$address}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx),
                default => throw new \InvalidArgumentException("Invalid address type provided"),
            },
            NetworkProtocol::UDP => match ($type) {
                NetworkAddress::NETWORK => stream_socket_server("udp://{$address}", $errno, $error, STREAM_SERVER_BIND, $ctx),
                NetworkAddress::LOCAL => stream_socket_server("udg://{$address}", $errno, $error, STREAM_SERVER_BIND, $ctx),
                default => throw new \InvalidArgumentException("Invalid address type provided"),
            },
            default => throw new \InvalidArgumentException("Invalid protocol provided"),
        };

        if ($socket === false || $errno !== 0) {
            throw new \RuntimeException($error, $errno);
        }

        stream_set_blocking($socket, false);

        return new Descriptor($socket);
    }

    protected function createClientSocket(
        string $address,
        int $port,
        NetworkProtocol $protocol,
        ?ServerContext $context,
        NetworkAddress $type
    ): ResourceInterface
    {
        $ctx = null;
        if ($context !== null) {
            $ctx = stream_context_create($context->getContextArray());
        }

        $socket = stream_socket_client(match ($protocol) {
            NetworkProtocol::TCP => match ($type) {
                NetworkAddress::NETWORK => "tcp://{$address}:{$port}",
                NetworkAddress::LOCAL => "unix://{$address}",
                default => throw new \InvalidArgumentException("Invalid address type provided"),
            },
            NetworkProtocol::UDP => match ($type) {
                NetworkAddress::NETWORK => "udp://{$address}",
                NetworkAddress::LOCAL => "udg://{$address}",
                default => throw new \InvalidArgumentException("Invalid address type provided"),
            },
            default => throw new \InvalidArgumentException("Invalid protocol provided"),
        }, $errno, $error, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $ctx);

        if ($socket === false || $errno !== 0) {
            throw new \RuntimeException($error, $errno);
        }

        stream_set_blocking($socket, false);

        return new Socket($socket, stream_socket_get_name($socket, false));
    }

    protected function accept(ResourceInterface $socket, bool $secure = false): ?ResourceInterface
    {
        $client = stream_socket_accept($socket->getResource(), null, peer_name: $peer);

        stream_set_blocking($client, false);

        if ($secure) {
            $negotiation = 0;
            do {
                $negotiation = stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
            } while ($negotiation === 0);

            if ($negotiation === false) {
                throw new \RuntimeException("Failed to establish a secure connection");
            }
        }

        return new Socket($client, $peer);
    }

    protected function read(ResourceInterface $socket, Closure $cb, bool $persistent = true): void
    {
        $this->onRead($socket, Task::create($cb, [$socket], $persistent));
    }

    protected function write(ResourceInterface $socket, Closure $cb, bool $persistent = true): void
    {
        $this->onWrite($socket, Task::create($cb, [$socket], $persistent));
    }

    public function open(
        string $address,
        int $port,
        Closure $callback,
        NetworkProtocol $protocol = NetworkProtocol::TCP,
        ServerContext $context = null,
        NetworkAddress $type = NetworkAddress::NETWORK,
    ): string
    {
        $socket = $this->createServerSocket($address, $port, $protocol, $context, $type);

        $accept = $protocol === NetworkProtocol::TCP ?
            $this->accept(...) :
            static function (ResourceInterface $resource) {
                stream_socket_recvfrom($resource->getResource(), 1, STREAM_PEEK, $peer);

                return new Socket($resource, $peer);
            };

        $dispatch = fn (...$args) => $this->queue($callback, ...$args);
        $isSecure = isset(($context?->getContextArray() ?? [])['ssl']);

        $this->read($socket, function (ResourceInterface $resource) use ($accept, $dispatch, $isSecure) {
            $connection = $accept($resource, $isSecure);

            $this->read($connection, static function (ResourceInterface $resource) use ($dispatch) {
                $buffer = buffer($resource);
                    $dispatch(new CallbackStream(
                        $buffer->read(...),
                        fn () => $buffer->size() > 0 ? $buffer->eof() : false,
                        static function (string $data) use ($resource) {
                            $size = strlen($data);
                            $bytes = 0;
                            while ($bytes < $size) {
                                $bytes += $resource->write(substr($data, $bytes));
                                suspend();
                            }
                        },
                        $resource->close(...))
                    );
            }, false);
        });

        return stream_socket_get_name($socket->getResource(), false);
    }

    public function connect(
        string $address,
        int $port,
        Closure $callback,
        NetworkProtocol $protocol = NetworkProtocol::TCP,
        ?ClientContext $context = null,
        NetworkAddress $type = NetworkAddress::NETWORK,
    ): void
    {
        $sockets = $this->createClientSocket($address, $port, $protocol, $context, $type);

        $buffer = buffer($sockets);

        $this->read($sockets, fn () => $buffer->write($sockets->read(65535)), true);

        $this->write(
            $sockets,
            fn () => $this->queue($callback, new CallbackStream(
                fn (int $size) => signal(fn (Closure $resume) => $resume($buffer->read($size))),
                    fn () => $buffer->size() > 0 ? $buffer->eof() : false,
                    fn (string $data) => signal(static function (Closure $resume) use ($data, $sockets) {
                        $size = strlen($data);
                        $bytes = 0;
                        while ($bytes < $size) {
                            $b = $sockets->write(substr($data, $bytes));
                            if ($b === false) {
                                return $resume(false);
                            }
                            $bytes += $b;
                            suspend();
                        }

                        $resume($bytes);
                    }),
                    $sockets->close(...)
                ),
                false
            ),
            false,
        );
    }
}
