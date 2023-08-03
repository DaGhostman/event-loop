<?php
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Scheduler\{Select, Uv, Event};
use function Onion\Framework\Loop\scheduler;
use function Onion\Framework\Loop\read;
use function Onion\Framework\Loop\write;
use function Onion\Framework\Loop\suspend;

require_once __DIR__ . '/../vendor/autoload.php';

scheduler(new Select());
scheduler()->addErrorHandler(var_dump(...));

// $server = scheduler()->open(
//     '127.0.0.1',
//     1234,
//     static function (
//         ResourceInterface $stream,
//     ): void {
//         $stream->write(
//             "HTTP/1.1 200 OK\r\n\r\nReceived: {$stream->read(65535)}\r\n"
//         );
//         $stream->close();
//     },
// );
//
// var_dump($server);

scheduler()->connect('93.184.216.34', 80, static function (
    ResourceInterface $resource,
): void {
    write($resource, "GET / HTTP/1.1\r\nHost: example.com\r\n\r\n");
    read($resource, function (ResourceInterface $resource) {
        $data = '';
        while (!$resource->eof()) {
            $data .= $resource->read(8);
            suspend();
        }

        echo $data . PHP_EOL;
    });

    $resource->close();
});
