<?php
namespace Onion\Framework\EventLoop\Stream;

class Stream
{
    private $resource;

    public function __construct($resource)
    {
        $this->resource = $resource;
    }

    public function line(): ?string
    {
        $result = @fgets($this->resource, $size);
        return $result !== false ? $result : null;
    }

    public function read(int $size = 8192): ?string
    {
        $result = @fread($this->resource, $size);
        return $result !== false ? $result : null;
    }

    public function write(string $contents): ?int
    {
        $result = @fputs($this->resource, $contents);
        return $result !== false ? $result : null;
    }

    public function eof(): bool
    {
        return feof($this->resource);
    }

    public function tell(): int
    {
        return ftell($this->resource);
    }

    public function close(): bool
    {
        return !$this->isClosed() ? @fclose($this->resource) : true;
    }

    public function size(): int
    {
        if (!$this->isClosed()) {
            return false;
        }

        return fstat($this->resource)['size'] ?? 0;
    }

    public function rewind()
    {
        return @rewind($this->resource);
    }

    public function seek(int $position, int $kind = SEEK_SET)
    {
        return @fseek($this->resource, $position, $kind) === 0;
    }

    public function isClosed(): bool
    {
        return !is_resource($this->resource);
    }

    public function isLocal(): bool
    {
        return stream_is_local($this->resource);
    }

    public function detach()
    {
        $this->readable = $this->writable = false;
        $resource = $this->resource;
        $this->resource = null;

        return $resource;
    }

    public function tty(): bool
    {
        return stream_isatty($this->resource);
    }
}
