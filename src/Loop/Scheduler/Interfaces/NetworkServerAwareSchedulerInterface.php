<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler\Interfaces;

use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Closure;
use Onion\Framework\Server\Interfaces\ContextInterface;
use \Onion\Framework\Loop\Resources\Interfaces\{ReadableResourceInterface, WritableResourceInterface};
use Onion\Framework\Loop\Scheduler\Types\{NetworkProtocol, NetworkAddressType};
interface NetworkServerAwareSchedulerInterface extends SchedulerInterface
{
    // /**
    //  * Create a TCP socket server and bind it to the given address
    //  *
    //  * @param string $address The complete address (incl. scheme)
    //  * @param Closure(ReadableResourceInterface $buffer, WritableResourceInterface $resource, Closure $closeFn): void $dispatchFunction the function to call when a message is received
    //  *
    //  * @return string the name of the socket (in `ip:port` format)
    //  */
    // public function listen(
    //     string $address,
    //     Closure $dispatchFunction,
    //     ?ContextInterface $context = null,
    // ): string;

    // /**
    //  * Create an UDP socket server and bind it to the given address
    //  *
    //  * @param string $address
    //  * @param Closure(ReadableResourceInterface $buffer, WritableResourceInterface $resource, Closure $closeFn): string|null $dispatchFunction the function to call when the message is received
    //  *
    //  * @return string the name of the socket (in `ip:port` format)
    //  */
    // public function bind(
    //     string $address,
    //     Closure $dispatchFunction,
    //     ?ContextInterface $context = null,
    // ): string;

    /**
     * Listen for a connection on the given address
     * and call the given callback when a connection is received
     * @param string $address The address to bind on, either a unix socket or a network socket
     * @param int $port The port to bind on (ignored if using a unix socket)
     * @param Closure(ReadableResourceInterface $buffer, WritableResourceInterface $resource, Closure $closeFn): string|null $callback A callback to invoke whenever a connection/message is received
     * @param NetworkProtocol $protocol The protocol to use (TCP or UDP)
     * @param ContextInterface|null $context context options specific for the socket being created
     * @param NetworkAddressType $type Type of the address (unix socket or network socket)
     * @return string
     */
    public function open(
        string $address,
        int $port,
        Closure $callback,
        NetworkProtocol $protocol = NetworkProtocol::TCP,
        ?ContextInterface $context = null,
        NetworkAddressType $type = NetworkAddressType::NETWORK,
    ): string;
}
