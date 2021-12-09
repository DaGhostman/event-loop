<?php

namespace Onion\Framework\Event;

use Onion\Framework\Loop\Interfaces\{
    SchedulerInterface,
    TaskInterface
};
use Psr\EventDispatcher\{
    EventDispatcherInterface,
    ListenerProviderInterface,
    StoppableEventInterface
};

use function Onion\Framework\Loop\{coroutine, signal};

class Dispatcher implements EventDispatcherInterface
{
    private $listenerProvider;

    public function __construct(ListenerProviderInterface $listenerProvider)
    {
        $this->listenerProvider = $listenerProvider;
    }

    public function dispatch(object $event): object
    {
        return signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($event) {
            coroutine(function () use ($task, $scheduler, $event) {
                $listeners = $this->listenerProvider->getListenersForEvent($event);

                try {
                    foreach ($listeners as $listener) {
                        if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                            break;
                        }

                        coroutine($listener, [$event]);
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
