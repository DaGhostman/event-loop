<?php
namespace Onion\Framework\EventLoop\Stream;

class Stream
{
    private $resource;
    private $readable;
    private $writable;

    public function __construct($resource, bool $readable = false, bool $writable = false)
    {
        $this->resource = $resource;
        $this->readable = $readable;
        $this->writable = $writable;
    }

    public function read(int $size = 8192): string
    {
        return @fread($this->resource, $size);
    }

    public function write(string $contents): int
    {
        return (int) @fwrite($this->resource, $contents);
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
        return rewind($this->resource);
    }

    public function isClosed(): bool
    {
        return !is_resource($this->resource);
    }

    public function isLocal(): bool
    {
        return stream_is_local($this->resource);
    }

    public function isReadable()
    {
        return $this->readable;
    }

    public function isWritable()
    {
        return $this->writable;
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

    public function __destruct()
    {
        $this->close();
    }
}
