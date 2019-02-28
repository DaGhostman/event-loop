<?php
namespace Onion\Framework\EventLoop\Stream;

class Stream
{
    private const READABLE_MODES = [
        'r',
        'rb',
        'rt',
        'r+',
        'r+b',
        'r+t',
        'w+',
        'w+b',
        'w+t',
        'a+',
        'a+b',
        'a+t',
        'x+',
        'x+b',
        'x+t',
        'c+',
        'c+b',
        'c+t',
    ];

    private const WRITABLE_MODES = [
        'r+',
        'r+b',
        'r+t',
        'w',
        'wb',
        'w+',
        'w+b',
        'w+t',
        'a',
        'ab',
        'a+',
        'a+b',
        'x',
        'xb',
        'x+',
        'x+b',
        'x+t',
        'c',
        'cb',
        'c+',
        'c+b',
        'c+t',
    ];

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

    public function seek(int $position, int $kind = SEEK_SET)
    {
        return fseek($this->resource, $position, $kind) === 0;
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
        if ($this->isLocal()) {
            $mode = stream_get_meta_data($this->resource)['mode'];

            return in_array($mode, self::READABLE_MODES, true);
        }

        return $this->readable;
    }

    public function isWritable()
    {
        if ($this->isLocal()) {
            $mode = stream_get_meta_data($this->resource)['mode'];

            return in_array($mode, self::WRITABLE_MODES, true);
        }

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
