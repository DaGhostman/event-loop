<?php

use function Onion\Framework\EventLoop\io;
use function Onion\Framework\EventLoop\loop;
use function Onion\Framework\EventLoop\timer;
require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

class CoSocket {
    protected $socket;

    public function __construct($socket) {
        $this->socket = $socket;
    }

    public function accept() {
        return stream_socket_accept($this->socket, -1);
    }

    public function read($size) {
        return fread($this->socket, $size);
    }

    public function write($string) {
        fwrite($this->socket, $string);
    }

    public function close() {
        @fclose($this->socket);
    }
}


function handleClient($socket) {
    loop(true);
    io($socket, function ($socket) {
        $data = fread($socket, 8192);
        io($socket, null, function ($socket) use ($data) {
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

        fwrite($socket, $response);
        fclose($socket);
        });
    });

    loop()->start();
}

echo 'Server' . PHP_EOL;
$port = 1235;
$socket = stream_socket_server("tcp://127.0.0.1:$port", $errNo, $errStr);
if (!$socket) throw new Exception($errStr, $errNo);
echo "Starting server at port $port...\n";
timer(0, function () use ($socket) {
    stream_set_blocking($socket, 0);

    $socket = new CoSocket($socket);
    $sock = $socket->accept();
    if ($sock) {
        handleClient($sock);
    }
});

loop()->start();
