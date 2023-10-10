<?php

use function Onion\Framework\Loop\{coroutine, repeat, read, scheduler, write};

require_once __DIR__ . '/../vendor/autoload.php';


scheduler(new \Onion\Framework\Loop\Scheduler\Ev());
scheduler()->addErrorHandler(var_dump(...));
$server = function (int $port) {
    echo "Listening on {$port}";
    scheduler()->open('0.0.0.0', $port, static function ($connection) {
        $data = read($connection, function ($conn) {
            return $conn->read(8192);
        });

        write($connection, "HTTP/1.1 200 OK\r\n\r\nReceived: {$data}\r\n\r\n");
        $connection->close();
    });
};
coroutine($server, [8080]);
// scheduler()->start();
