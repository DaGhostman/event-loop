<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Channels;

use BadMethodCallException;
use InvalidArgumentException;
use Onion\Framework\Loop\Interfaces\Channels\ChannelValueInterface;

class ChannelValue implements ChannelValueInterface
{
    public function __construct(
        private readonly mixed $value,
        private readonly bool $ok,
    ) {
    }

    public function value(): mixed
    {
        return $this->value instanceof ChannelValue ? $this->value->value() : $this->value;
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function offsetExists(mixed $offset): bool
    {
        return ($offset === 0 ||
            $offset === 1 ||
            strtolower($offset) === 'value' ||
            strtolower($offset) === 'val' ||
            strtolower($offset) === 'ok' ||
            strtolower($offset) === 'final'
        );
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException('Not supported');
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new BadMethodCallException("Unable to set {$offset} on readonly object");
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            0 => $this->value(),
            'value' => $this->value(),
            'val' => $this->value(),
            1 => $this->ok(),
            'ok' => $this->ok(),
            'final' => !$this->ok(),
            default => throw new InvalidArgumentException("Offset {$offset} does not exist"),
        };
    }
}
