<?php
namespace Onion\Framework\EventLoop\Stream;

class Stream
{
    private $resource;

    public function __construct($resource)
    {
        $this->resource = $resource;
    }

    public function read(int $size = 4096): ?string
    {
        $content = fread($this->resource, $size);

        return $content !== false ? $content : null;
    }

    public function write(string $contents, int $length = null): int
    {
        return (int) fwrite($this->resource, $contents, ($length ?? strlen($contents)));
    }

    public function eof(): bool
    {
        return feof($this->resource);
    }

    public function tell(): int
    {
        return ftell($this->resource);
    }

    public function seek(int $position, int $kind = SEEK_SET): bool
    {
        return $this->isSeekable() ?
            (fseek($this->resource, $position, $kind) === 0) : false;
    }

    public function close(): bool
    {
        return !$this->isClosed() ? fclose($this->resource) : true;
    }

    public function size(): int
    {
        if (!$this->isClosed()) {
            return false;
        }

        return fstat($this->resource)['size'] ?? 0;
    }

    public function isClosed(): bool
    {
        return !is_resource($this->resource);
    }

    public function isLocal(): bool
    {
        return stream_is_local($this->resource);
    }

    public function tty(): bool
    {
        return stream_isatty($this->resource);
    }
}
