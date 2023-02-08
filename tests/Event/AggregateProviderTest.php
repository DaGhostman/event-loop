<?php

namespace Tests\Event;

use Onion\Framework\Event\ListenerProviders\AggregateProvider;
use Onion\Framework\Event\ListenerProviders\SimpleProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use stdClass;
use Tests\Event\Stubs\EventA;

class AggregateProviderTest extends TestCase
{
    use ProphecyTrait;

    public function testAggregatedRetrieval()
    {
        $simple1 = $this->prophesize(SimpleProvider::class);
        $simple1->getListenersForEvent(
            Argument::type(EventA::class)
        )->willReturn([fn() => null]);
        $simple1->getListenersForEvent(Argument::any())->willReturn([]);

        $simple2 = $this->prophesize(SimpleProvider::class);
        $simple2->getListenersForEvent(
            Argument::type(EventA::class)
        )->willReturn([fn() => null]);
        $simple2->getListenersForEvent(Argument::any())->willReturn([]);

        $simple3 = $this->prophesize(SimpleProvider::class);
        $simple3->getListenersForEvent(
            Argument::type(stdClass::class)
        )->willReturn([]);
        $simple3->getListenersForEvent(Argument::any())->willReturn([]);

        $provider = new AggregateProvider();
        $provider->addProvider($simple1->reveal());
        $provider->addProvider($simple2->reveal());
        $provider->addProvider($simple3->reveal());

        $this->assertCount(2, iterator_to_array($provider->getListenersForEvent(new EventA()), false));
        $this->assertCount(0, iterator_to_array($provider->getListenersForEvent(new stdClass()), false));
    }
}
