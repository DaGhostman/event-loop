<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler\Interfaces;
use Closure;
use Onion\Framework\Client\Interfaces\ContextInterface;
use Onion\Framework\Loop\Scheduler\Types\NetworkAddressType;
use Onion\Framework\Loop\Scheduler\Types\NetworkProtocol;

interface NetworkClientAwareSchedulerInterface
{

    public function connect(
        string $address,
        int $port,
        Closure $callback,
        NetworkProtocol $protocol = NetworkProtocol::TCP,
        ?ContextInterface $context = null,
        NetworkAddressType $type = NetworkAddressType::NETWORK,
    ): void;
}
