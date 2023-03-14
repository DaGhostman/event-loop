<?php

namespace Onion\Framework\Event\ListenerProviders;

use Psr\EventDispatcher\ListenerProviderInterface;

class AggregateProvider implements ListenerProviderInterface
{
    /** @var ListenerProviderInterface[] */
    private array $providers = [];

    public function addProvider(ListenerProviderInterface ...$provider): void
    {
        $this->providers = array_merge($this->providers, $provider);
    }

    /**
     * @return \Generator
     *
     * @psalm-return \Generator<mixed, mixed, mixed, void>
     */
    public function getListenersForEvent(object $event): iterable
    {

        foreach ($this->providers as $provider) {
            yield from $provider->getListenersForEvent($event);
        }
    }
}
