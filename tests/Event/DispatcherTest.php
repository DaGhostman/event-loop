<?php

namespace Tests\Event;

use Exception;
use Onion\Framework\Event\Dispatcher;
use Onion\Framework\Test\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Tests\Event\Stubs\EventA;

class DispatcherTest extends TestCase
{
    use ProphecyTrait;

    private $provider;

    protected function setUp(): void
    {
        $this->provider = $this->prophesize(
            ListenerProviderInterface::class
        );
    }

    public function testDispatch()
    {
        $this->provider->getListenersForEvent(
            Argument::type(EventA::class)
        )->willReturn([
            fn (EventA $event) => $this->assertInstanceOf(EventA::class, $event)
        ]);

        $dispatcher = new Dispatcher($this->provider->reveal());
        $this->assertInstanceOf(EventA::class, $dispatcher->dispatch(new EventA()));
    }

    public function testDispatchException()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('test');

        $this->provider->getListenersForEvent(
            Argument::type(EventA::class)
        )->willReturn([
            function () {
                throw new \Exception('test');
            }
        ]);

        $dispatcher = new Dispatcher($this->provider->reveal());
        $dispatcher->dispatch(new EventA());
    }

    public function testDispatchOfStoppableEvent()
    {
        $this->expectOutputString('');
        $event = $this->prophesize(StoppableEventInterface::class);
        $event->isPropagationStopped()->willReturn(true);

        $this->provider->getListenersForEvent(
            Argument::any()
        )->willReturn([
            function () {
                echo 'test';
            }
        ]);

        $dispatcher = new Dispatcher($this->provider->reveal());
        $event = $dispatcher->dispatch($event->reveal());
        $this->assertTrue($event->isPropagationStopped());
        $this->assertInstanceOf(StoppableEventInterface::class, $event);
    }
}
