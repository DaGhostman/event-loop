<?php

use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use Onion\Framework\Loop\Result;
use function Onion\Framework\EventLoop\attach;

require __DIR__ . '/../vendor/autoload.php';

// ini_set('display_errors', 0);
// error_reporting(E_ALL ^ E_WARNING);

$scheduler = new Scheduler;

class Socket
{
    private $resource;
    private $peer;

    public function __construct($resource)
    {
        $this->resource = $resource;
        if ($this->resource) {
            stream_set_blocking($resource, false);
            $this->peer = stream_socket_get_name($resource, true);
        }
    }

    public function accept()
    {
        // yield onRead($this->resource);
        yield new Result(new self(stream_socket_accept($this->resource, 0)));
    }

    public function write(string $data): \Generator
    {
        yield onWrite($this->resource);
        $max = strlen($data);
        $size = stream_socket_sendto($this->resource, $data, null, $this->peer);
        while ($size !== false && ($size < $max)) {
            $size += stream_socket_sendto($this->resource, substr($data, $size), null, $this->peer);
        }

        yield new Result($size);
    }

    public function read(int $size = -1): \Generator
    {
        yield onRead($this->resource);
        yield new Result(stream_socket_recvfrom($this->resource, $size, null, $this->peer));
    }

    public function close()
    {
        yield new Result(@fclose($this->resource));
    }

    public function isConnected()
    {
        yield new Result(is_resource($this->resource));
    }
}

function coroutine(\Generator $coroutine) {
    return new Signal(
        function(Task $task, Scheduler $scheduler) use ($coroutine) {
            $task->send($scheduler->push($coroutine));
        }
    );
}

function onRead($socket): Signal {
    return new Signal(function (Task $task, Scheduler $scheduler) use ($socket) {
        $scheduler->attach($socket, $task);
    });
}

function onWrite($socket): Signal {
    return new Signal(function (Task $task, Scheduler $scheduler) use ($socket) {
        $scheduler->attach($socket, null, $task);
    });
}

function data(Socket $channel) {
    if (yield $channel->isConnected()) {
        if (yield $channel->write("HTTP/1.1 200 OK\r\nContent-Length: 13\r\n\r\nHello, World!\r\n\r\n")) {
            yield $channel->close();
        }
    } else {
        yield $channel->close();
    }
}

function echoTimes($msg, $max) {
    $task = yield getTaskId();
    for ($i = 1; $i <= $max; ++$i) {
        echo "{$msg}({$task}) iteration $i\n";
        if ($i === 5) {
            yield killTask($task);
        }
        yield;
    }
}

function go() {
    yield coroutine(echoTimes('foo', 10)); // print foo ten times
    echo " ---\n";
    yield coroutine(echoTimes('bar', 5)); // print bar five times
}


function killTask($tid) {
    return new Signal(
        function(Task $task, Scheduler $scheduler) use ($tid) {
            $task->send($scheduler->kill($tid));
            $scheduler->schedule($task);
        }
    );
}

function getTaskId() {
    return new Signal(function(Task $task, Scheduler $scheduler) {
        $task->send($task->getId());
        $scheduler->schedule($task);
    });
}


function listen() {

    $port = 1337;
    $socket = stream_socket_server("tcp://0.0.0.0:{$port}", $errCode, $errMessage);
    if (!$socket) {
        throw new \ErrorException($errMessage, $errCode);
    }
    // stream_set_blocking($socket, false);
    $socket = new Socket($socket);

    for (;;) {
        yield coroutine(
            data(yield $socket->accept())
        );
    }
}


$scheduler->push(listen());
$scheduler->run();


// if (!$socket) {
//     throw new \ErrorException($errMessage, $errCode);
// }
// stream_set_blocking($socket, 0);

// attach($socket, function (StreamInterface $stream) {
//     $pointer = $stream->detach();
//     $stream->attach($pointer);

//     $channel = @stream_socket_accept($pointer);
//     stream_set_blocking($channel, false);

//     attach($channel, function (StreamInterface $stream) {
//         $data = $stream->read(8192);
//         $size = strlen($data);
//         $stream->write("HTTP/1.1 200 OK\n");
//         $stream->write("Content-Type: text/plain\n");
//         $stream->write("Content-Length: {$size}\n");
//         $stream->write("\n");

//         $stream->write("{$data}");

//         defer(function () use ($stream) {
//             detach($stream);
//         });
//     });
// });

// loop()->start();
