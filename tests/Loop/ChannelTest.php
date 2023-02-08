<?php

namespace Tests\Loop;

use Onion\Framework\Loop\Channels\Channel;
use Onion\Framework\Test\TestCase;

use function Onion\Framework\Loop\coroutine;

class ChannelTest extends TestCase
{
    private Channel $channel;

    protected function setUp(): void
    {
        $this->channel = new Channel();
    }

    public function testChannel()
    {
        coroutine(function () {
            $this->channel->send(1);
            $this->channel->send(1);
            $this->channel->close();
        });

        coroutine(function () {
            $idx = 0;
            while ([$value, $ok] = $this->channel->recv()) {
                if (!$ok) {
                    break;
                }
                $this->assertSame(1, $value);
                $idx++;
            }
        });
    }

    public function testArrayDestruct()
    {
        coroutine(function () {

            $this->channel->send(1);
            $this->channel->send(1);
            $this->channel->close();
        });

        coroutine(function () {
            $item = $this->channel->recv();

            $this->assertArrayHasKey(0, $item);
            $this->assertArrayHasKey('value', $item);
            $this->assertArrayHasKey('val', $item);
            $this->assertArrayHasKey(1, $item);
            $this->assertArrayHasKey('ok', $item);
            $this->assertArrayHasKey('final', $item);

            [$value, $ok] = $item;
            $this->assertSame(1, $value);
            $this->assertTrue($ok);

            ['value' => $v, 'ok' => $o] = $item;
            $this->assertSame(1, $v);
            $this->assertTrue($o);

            ['val' => $v, 'final' => $o] = $item;
            $this->assertSame(1, $v);
            $this->assertFalse($o);
        });
    }
}
