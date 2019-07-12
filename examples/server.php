<?php
use function Onion\Framework\Loop\read;
use function Onion\Framework\Loop\write;
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Socket;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use function Onion\Framework\Loop\scheduler;

require_once __DIR__ . '/../vendor/autoload.php';

class Server
{
    public static function listen(int $port): Coroutine
    {
        $sock = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errmsg);
        if (!$sock) {
            throw new \RuntimeException($errmsg, $errno);
        }
        $socket = new Socket($sock);
        $socket->unblock();

        return new Coroutine(function (Socket $socket, int $port) {
            echo "Server listening on {$port}\n";
            while (true) {
                yield read($socket, function (ResourceInterface $socket) {
                    $connection = yield $socket->accept(null);
                    $connection->unblock();

                    if (!$connection->isAlive()) {
                        return;
                    }

                    yield read($connection, function (ResourceInterface $descriptor) {
                        $data = $descriptor->read(8192);

                        yield write($descriptor, function (ResourceInterface $descriptor) use ($data) {
                            $descriptor->write("HTTP/1.1 200 OK\r\n\r\n Received: {$data}\r\n");
                            $descriptor->close();
                        });
                    });
                });
            }
        }, [$socket, $port]);
    }
}

scheduler()->add(Server::listen(8080));

scheduler()->start();
