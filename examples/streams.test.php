<?php

use function Onion\Framework\EventLoop\io;
use function Onion\Framework\EventLoop\loop;
use Onion\Framework\EventLoop\Stream\StreamInterface;
require __DIR__ . '/../vendor/autoload.php';

$fp = fopen(__DIR__ . '/test.txt', 'r');

io($fp, function (StreamInterface $stream) {
    if ($stream->isReadable()) {
        echo "Readable!\n";

        $stream->rewind();
        $content = $stream->read();
    }

    if ($stream->isWritable()) {
        echo "Writable!\n";
        $stream->write("Hello World!");
    }
});

loop()->start();
