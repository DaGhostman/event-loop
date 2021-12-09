<?php

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\read;
use function Onion\Framework\Loop\scheduler;
use function Onion\Framework\Loop\write;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SocketInterface;
use Onion\Framework\Loop\Socket;

require_once __DIR__ . '/../vendor/autoload.php';

$server = function (int $port) {
    $socket = new Socket(stream_socket_server("tcp://0.0.0.0:{$port}"));
    $socket->unblock();

    echo "Server listening on {$port}\n";
    while (true) {
        read($socket, function (SocketInterface $socket) {
            $connection = $socket->accept(null);
            $connection->unblock();

            if (!$connection->isAlive()) {
                return;
            }

            read($connection, function (ResourceInterface $descriptor) {
                $data = $descriptor->read(8192);

                write($descriptor, function (ResourceInterface $descriptor) use ($data) {
                    $descriptor->write("HTTP/1.1 200 OK\r\n\r\nReceived: {$data}\r\n");
                    $descriptor->close();
                });
            });
        });
    }
};
coroutine($server, [8080]);
scheduler()->start();
