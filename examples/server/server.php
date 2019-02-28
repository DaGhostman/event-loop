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

$context = stream_context_create(['ssl' => [
    'local_cert' => __DIR__ . '/localhost.cert',
    'local_pk' => __DIR__ . '/localhost.key',
    'allow_self_signed' => true,
]]);

$port = 1337;
$plain = stream_socket_server("tcp://0.0.0.0:$port", $errNo, $errStr);
if (!$plain) throw new Exception($errStr, $errNo);
// $secure = stream_socket_server("tcp://0.0.0.0:3$port", $errNo, $errStr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
// if (!$secure) throw new Exception($errStr, $errNo);
$scheduler = scheduler();

echo "Starting server at port $port...\n";
timer(0.0, function () use ($plain) {
    stream_set_blocking($plain, 0);
    // stream_set_blocking($secure, 0);
    $channel = @stream_socket_accept($plain, 0);
    // if (!$channel) {
    //     $channel = @stream_socket_accept($secure, 0);

    //     if ($channel) {
    //         stream_set_blocking($channel, 1);
    //         @stream_socket_enable_crypto($channel, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    //         stream_set_blocking($channel, 0);
    //     }
    // }

    if (!$channel || !is_resource($channel)) {
        return;
    }

    $loop = loop(true);
    scheduler($loop);

    io($channel, function (Stream $stream) {
        $stream->rewind();
        $data = $stream->read();

        @list($meta, $body)=explode("\n\r", $data);
        $lines = explode("\n", $meta);
        preg_match('/^(?P<method>[A-Z]+) (?P<path>.*) (?P<protocol>.*)$/', array_shift($lines), $parts);

        if (!isset($parts['method'])) { // Corrupted when trying plain request on ssl port
            $stream->write("HTTP/1.0 400 Bad Request\nStrict-Transport-Security: max-age=300\n\n");
            return;
        }

        $parts = array_map('trim', $parts);
        $method = $parts['method'];
        $path = $parts['path'];
        $protocol = $parts['protocol'];

        $headers = [];
        foreach ($lines as $line) {
            preg_match('/^(?P<header>.*): (?P<value>.*)$/', $line, $matches);
            $headers[$matches['header']] = $matches['value'];
        }

        $response = "Received {$protocol} {$method} request for {$path}\n";
        $response .= "With headers:\n";
        foreach ($headers as $header => $value) {
            $response .= "\t{$header} -> {$value}\n";
        }

        $stream->write("HTTP/1.1 200 OK\nContent-Type: text/plain\n\n{$response}");
    });
    defer(function () use ($channel) {
        @fclose($channel);
    });

    loop()->start();
});

loop()->start();
