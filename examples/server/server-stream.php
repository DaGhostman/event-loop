<?php

use function Onion\Framework\EventLoop\attach;
use function Onion\Framework\EventLoop\defer;
use function Onion\Framework\EventLoop\detach;
use function Onion\Framework\EventLoop\loop;
use Onion\Framework\EventLoop\Stream\Stream;
require __DIR__ . '/../../vendor/autoload.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$port = 1337;
$socket = stream_socket_server("tcp://0.0.0.0:{$port}", $errCode, $errMessage);

if (!$socket) {
    throw new \ErrorException($errMessage, $errCode);
}
stream_set_blocking($socket, 0);

attach($socket, function (Stream $stream) {
    $channel = @stream_socket_accept($stream->detach(), 0);

    attach($channel, function (Stream $stream) {
        $data = $stream->read();
        $size = strlen($data);
        $stream->write("HTTP/1.1 200 OK\n");
        $stream->write("Content-Type: text/plain\n");
        $stream->write("Content-Length: {$size}\n");
        $stream->write("\n");

        $stream->write("{$data}");

        defer(function () use ($stream) {
            $stream->close();
            detach($stream->detach());
        });
    });
});

loop()->start();
