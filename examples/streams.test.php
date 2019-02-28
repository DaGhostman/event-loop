<?php

use function Onion\Framework\EventLoop\io;
use function Onion\Framework\EventLoop\loop;
use Onion\Framework\EventLoop\Stream\Stream;
require __DIR__ . '/../vendor/autoload.php';

$fp = fopen(__DIR__ . '/test.txt', 'r');

io($fp, function (Stream $stream) {
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
