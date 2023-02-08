<?php

namespace Onion\Framework\Event;

use Psr\EventDispatcher\{EventDispatcherInterface, ListenerProviderInterface, StoppableEventInterface};

use function Onion\Framework\Loop\signal;

class Dispatcher implements EventDispatcherInterface
{
    private $listenerProvider;

    public function __construct(ListenerProviderInterface $listenerProvider)
    {
        $this->listenerProvider = $listenerProvider;
    }

    public function dispatch(object $event): object
    {

        $listeners = (function ($event): \Generator {
            yield from $this->listenerProvider->getListenersForEvent($event);
        })($event);


        $next = function ($event, $iterator) use (&$next): object  {
            return signal(function (\Closure $resume) use ($event, &$next, $iterator) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                $resume($event);
                return;
            }

            $current = $iterator->current();
            if ($current) {
                $iterator->next();
                $resume($next($current($event) ?? $event, $iterator));
            } else {
                $resume($event);
            }
        });
    };

        return $next($event, $listeners);
    }
}
