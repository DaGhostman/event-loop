<?php

namespace Tests\Loop;

use BadMethodCallException;
use Onion\Framework\Loop\Channels\ChannelValue;
use Onion\Framework\Test\TestCase;

class ChannelValueTest extends TestCase
{
    public function testGetters()
    {
        $value = new ChannelValue('foo', false);

        $this->assertSame('foo', $value[0]);
        $this->assertSame('foo', $value['value']);
        $this->assertSame('foo', $value['val']);

        $this->assertFalse($value[1]);
        $this->assertFalse($value['ok']);
        $this->assertTrue($value['final']);
    }

    public function testIsset()
    {
        $value = new ChannelValue('foo', false);

        $this->assertArrayHasKey(0, $value);
        $this->assertArrayHasKey(1, $value);
        $this->assertArrayHasKey('value', $value);
        $this->assertArrayHasKey('val', $value);
        $this->assertArrayHasKey('ok', $value);
        $this->assertArrayHasKey('final', $value);
    }
}
