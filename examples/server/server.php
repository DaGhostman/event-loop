<?php

use function Onion\Framework\EventLoop\defer;
use function Onion\Framework\EventLoop\io;
use function Onion\Framework\EventLoop\loop;
use function Onion\Framework\EventLoop\scheduler;
use function Onion\Framework\EventLoop\timer;
use Onion\Framework\EventLoop\Stream\Stream;
require __DIR__ . '/../../vendor/autoload.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$port = 1337;
$plain = stream_socket_server("tcp://127.0.0.1:$port", $errNo, $errStr);
if (!$plain) throw new Exception($errStr, $errNo);
$scheduler = scheduler();
stream_set_blocking($plain, 0);

echo "Starting server at port $port...\n";
timer(0.0, function () use ($plain) {
    $channel = @stream_socket_accept($plain, 0);

    if (!$channel || !is_resource($channel)) {
        return;
    }

    io($channel, function (Stream $stream) {
        $data = $stream->read();
        $size = strlen($data);
        $stream->write("HTTP/1.1 200 OK\n");
        $stream->write("Content-Type: text/plain\n");
        $stream->write("Content-Length: {$size}\n");
        $stream->write("\n");

        $stream->write("{$data}");

        defer(function () use ($stream) {
            $stream->close();
        });
    });
});

loop()->start();
