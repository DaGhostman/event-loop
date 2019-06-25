<?php

use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use Onion\Framework\Loop\Result;
use function Onion\Framework\EventLoop\attach;

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

$scheduler = new Scheduler;

class Socket
{
    private $resource;
    private $peer;

    public function __construct($resource)
    {
        $this->resource = $resource;
        if ($this->resource) {
            $this->peer = stream_socket_get_name($resource, true);
        }
    }

    public function accept()
    {
        $this->unblock();
        yield from read($this, function (Socket $socket) {
            return new self(stream_socket_accept($this->resource, 0));
        });
    }

    public function write(string $data)
    {
        // yield from write($this, function (Socket $connection) use ($data) {
            // yield onWrite($this->resource);
            $max = strlen($data);
            $size = stream_socket_sendto($this->resource, $data, null, $this->peer);
            while ($size !== false && ($size < $max)) {
                $size += stream_socket_sendto($this->resource, substr($data, $size), null, $this->peer);
            }

            // yield new Result($size);
            return $size;
        // });
    }

    public function read(int $size = -1)
    {
        return stream_socket_recvfrom($this->getResource(), $size, null, $this->peer);
    }

    public function getContents()
    {
        return $this->isConnected() ? stream_get_contents($this->getResource()) : '';
    }

    public function close()
    {
        return fclose($this->resource);
    }

    public function isConnected()
    {
        return is_resource($this->getResource());
    }

    public function unblock()
    {
        return stream_set_blocking($this->resource, false);
    }

    public function block()
    {
        return stream_set_blocking($this->resource, true);
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function __debugInfo()
    {
        return [
            'resource' => $this->resource,
            'peer' => $this->peer,
        ];
    }
}

function read(Socket $socket, callable $callback) {
    yield onRead($socket->getResource());

    yield new Result(call_user_func($callback, $socket));
}

function write(Socket $socket, callable $callback) {
    yield onWrite($socket->getResource());

    yield new Result(call_user_func($callback, $socket));
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
        $task->send($scheduler->attach($socket, $task));
    });
}

function onWrite($socket): Signal {
    return new Signal(function (Task $task, Scheduler $scheduler) use ($socket) {
        $scheduler->attach($socket, null, $task);
    });
}

function data(Socket $socket) {
    if ($socket->isConnected()) {
        // Trigger Connect
        $socket->unblock();

        yield read($socket, function (Socket $socket) {
            if (!$socket->isConnected()) {
                $socket->close();
                return;
            }
        });

        yield write($socket, function (Socket $socket) {
                $data = $socket->getContents();

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
                $socket->write($response);
                $socket->close();
            });
    } else {
        // trigger disconnect
        killTask(getTaskId());
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

function getTaskCount() {
    return new Signal(function (Task $task, Scheduler $scheduler) {
        $task->send(count($scheduler));
        $scheduler->schedule($task);
    });
}


function listen(int $port) {
    try {
    $socket = @stream_socket_server("tcp://0.0.0.0:{$port}", $errCode, $errMessage, STREAM_SERVER_LISTEN | STREAM_SERVER_BIND);
    if (!$socket) {
        throw new \ErrorException($errMessage, $errCode);
    }
    $connection = new Socket($socket);

    for (;;) {

        yield coroutine(data(yield $connection->accept()));
        // yield coroutine(yield read(yield $connection->accept(), function (Socket $connection) {
        //     if (yield $connection->isConnected()) {
        //         return (yield coroutine(yield read($connection, 'data')));
        //     }
        // }));
    }
} catch (\Throwable $ex) {
    var_dump($ex);
}
}


$scheduler->push(listen(8080));
$scheduler->start();


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
