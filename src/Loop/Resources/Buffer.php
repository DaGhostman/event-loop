<?php

declare(strict_types=1);

namespace Onion\Framework\Loop\Resources;

use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\ResourceInterface;

class Buffer extends Descriptor implements ResourceInterface
{

    public function __construct() {
        parent::__construct(fopen('file://' . tempnam(sys_get_temp_dir(), 'buffer'), 'r+b'));
    }

    public function write(string $data): int|false
    {
        $cursor = $this->tell();
        $this->seek(0, SEEK_END);
        $result =  parent::write($data);
        $this->seek($cursor, SEEK_SET);


        return $result;
    }

    public function seek(int $position, int $whence = SEEK_SET): void
    {
        fseek($this->getResource(), $position, $whence);
    }

    public function tell(): int
    {
        return ftell($this->getResource());
    }

    public function rewind(): void
    {
        rewind($this->getResource());
    }

    // public function flush(): void
    // {
    //     $this->contents = '';
    //     $this->size = 0;
    //     $this->cursor = 0;
    // }

    // public function drain(int $cursor = null): void
    // {
    //     $this->contents = substr($this->contents, 0, $cursor ?? $this->cursor);
    //     $this->size = strlen($this->contents);
    //     $this->cursor = 0;
    // }

    public function size(): int
    {
        return fstat($this->getResource())['size'] ?? -1;
    }

    public function __toString(): string
    {
        return stream_get_contents($this->getResource());
    }
}
