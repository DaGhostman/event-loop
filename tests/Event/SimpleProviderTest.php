<?php

namespace Tests\Event;

use Onion\Framework\Event\ListenerProviders\SimpleProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Event\Stubs\EventA;

class SimpleProviderTest extends TestCase
{
    public function testEventRetrievalByClassName()
    {
        $provider = new SimpleProvider([
            EventA::class => [fn () => null],
        ]);

        $this->assertCount(1, $provider->getListenersForEvent(new EventA));
    }

    public function testEventRetrievalByTransformedName()
    {
        $provider = new SimpleProvider([
            'tests.event.stubs.eventa' => [fn () => null],
        ]);

        $this->assertCount(1, $provider->getListenersForEvent(new EventA));
    }

    public function testEventRetrievalOfNonExisting()
    {
        $provider = new SimpleProvider([
            'tests.events.stubs.eventa' => [fn () => null],
        ]);

        $this->assertCount(0, $provider->getListenersForEvent(new stdClass));
    }
}
