<?php
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Scheduler\{Select, Uv, Event};
use function Onion\Framework\Loop\scheduler;
use function Onion\Framework\Loop\read;
use function Onion\Framework\Loop\write;
use function Onion\Framework\Loop\coroutine;

require_once __DIR__ . '/../vendor/autoload.php';

scheduler(new Select());
scheduler()->addErrorHandler(var_dump(...));

coroutine(function () {
    scheduler()->connect('127.0.0.1', 8080, static function (
        ResourceInterface $resource,
    ): void {
        write($resource, "GET / HTTP/1.1\r\nHost: example.com\r\n\r\n");
        echo read($resource, function (ResourceInterface $resource) {
            $data = '';
            while (!$resource->eof()) {
                $data .= $resource->read(8);
            }

            return $data;
        });

        $resource->close();
    });
});
