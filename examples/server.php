<?php

use function Onion\Framework\EventLoop\io;
use function Onion\Framework\EventLoop\loop;
use function Onion\Framework\EventLoop\timer;
use Onion\Framework\EventLoop\Stream\Stream;
use function Onion\Framework\EventLoop\scheduler;
use function Onion\Framework\EventLoop\coroutine;
require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$port = 1337;
$socket = stream_socket_server("tcp://0.0.0.0:$port", $errNo, $errStr);
$scheduler = scheduler();
if (!$socket) throw new Exception($errStr, $errNo);
echo "Starting server at port $port...\n";
timer(0.0, function () use ($socket) {
    stream_set_blocking($socket, 0);
    $channel = @stream_socket_accept($socket, 0);

    if ($channel) {
        loop(true);

        io($socket, function (Stream $stream) use ($channel) {
            $data = $stream->read();

            return io($channel, null, function (Stream $stream) use ($data) {
                $msg = "Received following request:\n\n$data";
                $msgLength = strlen($msg);
                $response = <<<RES
HTTP/1.1 200 OK\r
Content-Type: text/plain\r
Content-Length: $msgLength\r
Connection: close\r
\r
$msg
RES;

                $stream->write($response);
                $stream->close();
            });
        });

        coroutine(function () {
            echo "Test\n";
        });

        loop()->start();
    }
});

loop()->start();
