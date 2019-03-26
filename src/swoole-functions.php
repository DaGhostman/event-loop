<?php
namespace Onion\Framework\EventLoop;

use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Stream\StreamInterface;
use Onion\Framework\EventLoop\Interfaces\LoopInterface;
use Onion\Framework\EventLoop\Interfaces\TaskInterface;
use Onion\Framework\EventLoop\Task\Timer;

if (!function_exists(__NAMESPACE__ . '\select')) {
    function select(&$read, &$write, &$error, ?int $timeout = null) {
        return @swoole_select($read, $write, $error, $timeout === null ? null : (float) $timeout);
    }
}

if (!function_exists(__NAMESPACE__ . '\loop')) {
    function &loop(): LoopInterface {
        static $loop = null;
        if ($loop === null) {
            $loop = new class extends Loop
            {
                public function tick(): void
                {
                    swoole_event_wait();
                }

                public function start(): void
                {
                }

                public function stop(): void
                {
                    swoole_event_exit();
                }

                public function push(TaskInterface $task, int $type = self::TASK_IMMEDIATE): TaskInterface
                {
                    throw new \BadMethodCallException(
                        "Use functional API instead"
                    );

                    return $task;
                }

                public function attach(StreamInterface $resource, ?callable $onRead = null, ?callable $onWrite = null): bool
                {
                    return swoole_event_add($resource->detach(), function ($sock) use ($onRead) {
                        $stream = new Stream($sock);
                        call_user_func($onRead, $stream);
                        $stream->detach();
                    }, function ($sock) use ($onWrite) {
                        $stream = new Stream($sock);
                        call_user_func($onWrite, $stream);
                        $stream->detach();
                    });
                }

                public function detach(StreamInterface $resource): bool
                {
                    $pointer = $resource->detach();
                    if (swoole_event_isset($pointer)) {
                        return swoole_event_del($pointer);
                    }
                    $resource->attach($pointer);
                    $resource->close();

                    return false;
                }
            };
        }

        return $loop;
    }
}

if (!function_exists(__NAMESPACE__ . '\interval')) {
    function timer(int $interval, callable $callback): Timer {
        $timer = swoole_timer_tick($interval, $callback);

        return new class($timer) extends Timer {
            private $timer;

            public function __construct($timer) {
                $this->timer = $timer;
            }
            public function run() {}
            public function stop(): void {
                swoole_timer_clear($this->timer);
                parent::stop();
            }
        };
    }
}

if (!function_exists(__NAMESPACE__ . '\delay')) {
    function after(int $delay, callable $callback): Timer {
        $timer = swoole_timer_after($delay, $callback);
        return new class($timer) extends Timer {
            private $timer;

            public function __construct($timer) {
                $this->timer = $timer;
            }

            public function run() {}
            public function stop(): void {
                swoole_timer_clear($this->timer);
                parent::stop();
            }
        };
    }
}

if (!function_exists(__NAMESPACE__ . '\defer')) {
    function defer(callable $callable): void {
        swoole_event_defer($callable);
    }
}

if (!function_exists(__NAMESPACE__ . '\coroutine')) {
    function coroutine(callable $callable): void {
        \Swoole\Coroutine::create($callable);
    }
}
