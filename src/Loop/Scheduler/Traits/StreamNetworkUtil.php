<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler\Traits;

use Closure;
use Onion\Framework\Client\Interfaces\ContextInterface as ClientContext;
use Onion\Framework\Loop\Types\NetworkAddress;
use Onion\Framework\Loop\Types\NetworkProtocol;
use Onion\Framework\Loop\Resources\CallbackStream;
use Onion\Framework\Loop\Socket;
use Onion\Framework\Server\Interfaces\ContextInterface as ServerContext;
use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Task;

use function Onion\Framework\Loop\{buffer, signal, write, read};

trait StreamNetworkUtil
{
    private const SERVER_SECURITY_METHODS = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
    private const CLIENT_SECURITY_METHODS = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    protected function createServerSocket(
        string $address,
        int $port,
        NetworkProtocol $protocol,
        ?ServerContext $context,
        NetworkAddress $type
    ): ResourceInterface {

        $ctx = stream_context_create(array_merge([
            'socket' => [
                'tcp_nodelay' => true,
            ]
        ], $context?->getContextArray() ?? []));

        $socket = match ($protocol) {
            NetworkProtocol::TCP => match ($type) {
                NetworkAddress::NETWORK => stream_socket_server(
                    "tcp://{$address}:{$port}",
                    $errno,
                    $error,
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                    $ctx,
                ),
                NetworkAddress::LOCAL => stream_socket_server(
                    "unix://{$address}",
                    $errno,
                    $error,
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                    $ctx,
                ),
                default => throw new \InvalidArgumentException("Invalid address type provided"),
            },
            NetworkProtocol::UDP => match ($type) {
                NetworkAddress::NETWORK => stream_socket_server(
                    "udp://{$address}",
                    $errno,
                    $error,
                    STREAM_SERVER_BIND,
                    $ctx,
                ),
                NetworkAddress::LOCAL => stream_socket_server(
                    "udg://{$address}",
                    $errno,
                    $error,
                    STREAM_SERVER_BIND,
                    $ctx
                ),
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
        ?ClientContext $context,
        NetworkAddress $type
    ): ResourceInterface {
        $ctx = stream_context_create(array_merge([
            'socket' => [
                'tcp_nodelay' => true,
            ]
        ], $context?->getContextArray() ?? []));

        $socket = stream_socket_client(match ($protocol) {
            NetworkProtocol::TCP => match ($type) {
                NetworkAddress::NETWORK => "tcp://{$address}:{$port}",
                NetworkAddress::LOCAL => "unix://{$address}",
                default => throw new \InvalidArgumentException("Invalid address type provided"),
            },
            NetworkProtocol::UDP => match ($type) {
                NetworkAddress::NETWORK => "udp://{$address}:{$port}",
                NetworkAddress::LOCAL => "udg://{$address}",
                default => throw new \InvalidArgumentException("Invalid address type provided"),
            },
            default => throw new \InvalidArgumentException("Invalid protocol provided"),
        }, $errno, $error, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $ctx);

        if ($socket === false || $errno !== 0) {
            throw new \RuntimeException($error, $errno);
        }

        $client = new Socket($socket, stream_socket_get_name($socket, true) ?: null);
        $client->unblock();

        return signal(function ($resume, TaskInterface $task, SchedulerInterface $scheduler) use ($client) {
            $scheduler->onWrite($client, Task::create(function () use ($resume, $client) {
                $client->negotiateSecurity(self::CLIENT_SECURITY_METHODS);

                $resume($client);
            }));
        });
    }

    protected function accept(ResourceInterface $socket, bool $secure = false): ?ResourceInterface
    {
        $client = stream_socket_accept($socket->getResource(), null, peer_name: $peer);
        if (!$client) {
            return null;
        }

        $client = new Socket($client, $peer);

        $client->unblock();

        return signal(function ($resume, TaskInterface $task, SchedulerInterface $scheduler) use ($client) {
            $scheduler->onRead($client, Task::create(function () use ($resume, $client) {
                $client->negotiateSecurity(self::SERVER_SECURITY_METHODS);

                $resume($client);
            }));
        });
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
    ): string {
        $socket = $this->createServerSocket($address, $port, $protocol, $context, $type);

        $accept = $protocol === NetworkProtocol::TCP ?
            $this->accept(...) :
            static function (ResourceInterface $resource) {
                stream_socket_recvfrom($resource->getResource(), 1, STREAM_PEEK, $peer);

                return new Socket($resource, $peer);
            };

        $this->read($socket, function (ResourceInterface $resource) use ($accept, $callback) {
            $connection = $accept($resource);

            if (!$connection) {
                return;
            }

            $this->read($connection, static fn (ResourceInterface $resource) => $callback($resource), true);
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
    ): void {
        $resource = $this->createClientSocket($address, $port, $protocol, $context, $type);

        $this->write(
            $resource,
            fn () => $callback($resource),
            false,
        );
    }
}
