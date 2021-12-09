<?php

namespace Onion\Framework\Event\ListenerProviders;

use Psr\EventDispatcher\ListenerProviderInterface;

class AggregateProvider implements \IteratorAggregate, ListenerProviderInterface
{
    private $providers = [];

    public function addProvider(ListenerProviderInterface ...$provider): void
    {
        $this->providers = array_merge($this->providers, $provider);
    }

    public function getListenersForEvent(object $event): iterable
    {
        return (function ($event, $providers) {
            foreach ($providers as $provider) {
                yield from $provider->getListenersForEvent($event);
            }
        })($event, $this->providers);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->providers);
    }
}
