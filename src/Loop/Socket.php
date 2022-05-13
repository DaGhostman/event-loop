<?php

namespace Onion\Framework\Loop;

use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\{
    ResourceInterface,
    SchedulerInterface,
    SocketInterface,
    TaskInterface
};
use Throwable;

class Socket extends Descriptor implements SocketInterface
{
    public function read(int $size, int $flags = 0): string
    {
        return stream_socket_recvfrom(
            $this->getResource(),
            $size,
            $flags
        );
    }

    public function write(string $data, int $flags = 0): int
    {
        return stream_socket_sendto(
            $this->getResource(),
            $data,
            $flags,
            stream_socket_get_name($this->getResource(), true)
        );
    }

    public function accept(?int $timeout = 0): ResourceInterface
    {
        $waitFn = function (
            TaskInterface $task,
            SchedulerInterface $scheduler,
            ResourceInterface $resource,
            ?int $timeout
        ): void {
            try {
                $resource->wait();
                $descriptor = new Descriptor(@stream_socket_accept($resource->getResource(), $timeout));

                $descriptor->unblock();

                $task->resume($descriptor);
            } catch (Throwable $ex) {
                $task->throw($ex);
            } finally {
                $scheduler->schedule($task);
            }
        };

        return signal(function (callable $resume, TaskInterface $task, SchedulerInterface $scheduler) use ($timeout, $waitFn) {
            $scheduler->schedule(Task::create($waitFn, [$task, $scheduler, $this, $timeout]));
        });
    }
}
