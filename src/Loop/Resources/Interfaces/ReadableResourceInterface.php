<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Resources\Interfaces;

use Countable;
use Stringable;

interface ReadableResourceInterface extends Stringable
{
    public function read(int $length): ?string;
    public function tell(): int;
    public function seek(int $position, int $whence = SEEK_SET): void;

    public function rewind(): void;
    public function size(): int;

    public static function fromString(string $data): self;
}
