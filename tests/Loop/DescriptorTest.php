<?php

namespace Tests;

use InvalidArgumentException;
use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Task;
use Onion\Framework\Loop\Types\Operation;
use Onion\Framework\Test\TestCase;

use function Onion\Framework\Loop\scheduler;
use function Onion\Framework\Loop\coroutine;

class DescriptorTest extends TestCase
{
    private $resource;
    private $name;

    protected function setUp(): void
    {
        $this->resource = fopen(tempnam(sys_get_temp_dir(), uniqid()), 'a+');
        $this->name = stream_get_meta_data($this->resource)['uri'] ?? null;
    }

    public function testReadingFromFile()
    {
        file_put_contents($this->name, 'example-data');
        $resource = new Descriptor($this->resource);
        $this->assertSame('example-data', $resource->read(1024));
    }

    public function testWritingToFile()
    {
        $resource = new Descriptor($this->resource);
        $resource->write('example-data');
        fseek($resource->getResource(), 0);
        $this->assertSame('example-data', $resource->read(1024));
    }

    public function testReadOnWriteableStream()
    {
        $resource = new Descriptor(fopen(tempnam(sys_get_temp_dir(), uniqid()), 'w'));

        $this->assertFalse($resource->read(1024));

        fclose($resource->getResource());
    }

    public function testWriteOnReadableStream()
    {
        $resource = new Descriptor(fopen(tempnam(sys_get_temp_dir(), uniqid()), 'r'));

        $this->assertFalse($resource->write('test'));

        fclose($resource->getResource());
    }

    public function testBlock()
    {
        $resource = new Descriptor(stream_socket_server('127.0.0.1:1234'));
        $this->assertTrue($resource->block());
        $this->assertTrue(stream_get_meta_data($resource->getResource())['blocked']);
        $resource->close();
    }

    public function testBlockOnClosed()
    {
        $resource = new Descriptor(stream_socket_server('127.0.0.1:1234'));
        $resource->close();
        $this->assertFalse($resource->block());
    }

    public function testUnblock()
    {
        $resource = new Descriptor(stream_socket_server('127.0.0.1:1234'));
        $this->assertTrue($resource->unblock());
        $this->assertFalse(stream_get_meta_data($resource->getResource())['blocked']);
        $resource->close();
    }

    public function testUnblockOnClosed()
    {
        $resource = new Descriptor(stream_socket_server('127.0.0.1:1234'));
        $resource->close();
        $this->assertFalse($resource->unblock());
    }

    public function testLocking()
    {
        $resource = new Descriptor($this->resource);
        $this->assertTrue($resource->lock());
        $this->assertTrue($resource->unlock());
    }

    public function testLockingOnUnsupportedResource()
    {
        $resource = new Descriptor(STDIN);
        $this->assertFalse($resource->lock());
    }

    protected function tearDown(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
        unlink($this->name);
    }
}
