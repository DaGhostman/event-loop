<?php
namespace Onion\Framework\Event\ListenerProviders;

use Psr\EventDispatcher\ListenerProviderInterface;

class SimpleProvider implements ListenerProviderInterface
{
    private $listeners = [];

    public function __construct($listeners)
    {
        $this->listeners = $listeners;
    }

    public function getListenersForEvent(object $event): iterable
    {
        $class = get_class($event);
        $transformed = str_replace('\\', ".", ltrim($class, '\\'));

        return $this->listeners[get_class($event)] ??
            $this->listeners[$transformed] ??
            [];
    }
}
