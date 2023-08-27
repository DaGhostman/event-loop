<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Resources\Interfaces;

interface WritableResourceInterface
{
    public function write(string $data): int;
}
