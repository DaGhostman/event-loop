<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Types;

enum NetworkSocketType: string
{
    case TCP = 'tcp';
    case UDP = 'udp';
    case UNIX = 'unix';
    case UDG = 'udg';
    case TLS = 'tls';
}
