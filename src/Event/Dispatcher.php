<?php
namespace Onion\Framework\Event;

use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Result;
use Onion\Framework\Loop\Signal;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

class Dispatcher implements EventDispatcherInterface
{
    private $listenerProvider;

    public function __construct(ListenerProviderInterface $listenerProvider)
    {
        $this->listenerProvider = $listenerProvider;
    }

    public function dispatch(object $event): Signal
    {
        return new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($event) {
            $scheduler->add(new Coroutine(function () use ($task, $scheduler, $event) {
                $listeners = $this->listenerProvider->getListenersForEvent($event);

                foreach ($listeners as $listener) {
                    if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                        break;
                    }

                    yield call_user_func($listener, $event);
                }

                $task->send($event);
                $scheduler->schedule($task);

                yield;
            }));
        });
    }
}
