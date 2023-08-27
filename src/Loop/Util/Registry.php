<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Util;

class Registry
{
    private array $storage = [];
    public function __construct()
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->storage[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->storage[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->storage[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->storage[$key]);
    }

    public function pop(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }
}
