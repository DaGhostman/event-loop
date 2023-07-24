<?php

use Onion\Framework\Loop\Resources\Buffer;
use Onion\Framework\Loop\Resources\Interfaces\WritableResourceInterface;
use function Onion\Framework\Loop\{scheduler, coroutine, suspend};

var_dump(memory_get_peak_usage(true) / 1024 / 1024);

require __DIR__ . '/../vendor/autoload.php';

// scheduler(new \Onion\Framework\Loop\Scheduler\Select());
scheduler(new \Onion\Framework\Loop\Scheduler\Uv());
scheduler()->addErrorHandler(var_dump(...));

coroutine(function () {
    $scheduler = scheduler();

    $address = $scheduler->listen('tcp://0.0.0.0:1234', function (Buffer $payload, WritableResourceInterface $output, Closure $close = null) {
        $close ??= fn () => null;

        $data = '';

        if (($headerEnd = strpos($payload, "\r\n\r\n")) !== false) {
            // rewind the buffer to the end of the header
            $payload->seek($headerEnd + 4);
            // remove trailing data from header section
            $data = substr($payload, 0, $headerEnd);
        }

        $body = '';
        if (preg_match('/Content-Length: (\d+)/', (string) $payload, $matches)) {
            $length = (int) $matches[1];
            $body .= $payload->read((int) $length) ?? '';
        } else {
            while (!$payload->eof()) {
                $body .= $payload->read(65535) ?? '';
                suspend();
            }
        }

        $b = trim("{$data}\r\n{$body}");

        $d = gzencode($b);
        $len = strlen($d);
        $output->write("HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Encoding: gzip\r\nContent-Length: {$len}\r\nConnection: close\r\n\r\n{$d}");

        $close();
    });

    var_dump($address);

    [$host] = dns_get_record('google.com', DNS_A);

    $scheduler->connect(
        "tcp://{$host['ip']}:80",
        "HTTP/1.1 GET /\r\n\r\n",
        static function (Buffer $buffer) {
            echo 'Received response';
            coroutine(function () {
                var_dump(memory_get_peak_usage(true) / 1024 / 1024);
            });
        }
    );

    // var_dump($address);
});


