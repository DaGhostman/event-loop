<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler\Types;

enum LockType
{
    case SHARED;
    case EXCLUSIVE;
}
