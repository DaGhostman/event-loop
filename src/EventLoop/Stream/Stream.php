<?php
namespace Onion\Framework\EventLoop\Stream;

class Stream
{
    private $resource;

    public function __construct($resource)
    {
        $this->resource = $resource;
    }
}
