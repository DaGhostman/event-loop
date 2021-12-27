<?php

namespace Onion\Framework\Event;

use function Onion\Framework\Loop\{coroutine, signal, tick};
use Onion\Framework\Loop\Interfaces\{SchedulerInterface, TaskInterface};
use Psr\EventDispatcher\{EventDispatcherInterface, ListenerProviderInterface, StoppableEventInterface};

class Dispatcher implements EventDispatcherInterface
{
    private $listenerProvider;

    public function __construct(ListenerProviderInterface $listenerProvider)
    {
        $this->listenerProvider = $listenerProvider;
    }

    public function dispatch(object $event): object
    {
        return signal(function (callable $resume, TaskInterface $task, SchedulerInterface $scheduler) use ($event) {
            coroutine(function () use ($resume, $task, $scheduler, $event) {
                try {
                    foreach ($this->listenerProvider->getListenersForEvent($event) as $listener) {
                        if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                            break;
                        }

                        $listener($event);
                        tick();
                    }

                    $task->resume($event);
                } catch (\Throwable $ex) {
                    $task->throw($ex);
                } finally {
                    $scheduler->schedule($task);
                }
            });
        });
    }
}
