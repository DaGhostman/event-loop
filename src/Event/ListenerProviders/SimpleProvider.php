<?php

namespace Onion\Framework\Event\ListenerProviders;

use Psr\EventDispatcher\ListenerProviderInterface;

class SimpleProvider implements ListenerProviderInterface
{
    public function __construct(private readonly array $listeners)
    {
    }

    public function getListenersForEvent(object $event): iterable
    {
        $class = get_class($event);
        $transformed = strtolower(str_replace('\\', ".", ltrim($class, '\\')));

        return $this->listeners[$class] ??
            $this->listeners[$transformed] ??
            [];
    }
}
