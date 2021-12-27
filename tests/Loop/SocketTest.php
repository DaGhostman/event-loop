<?php

namespace Tests\Loop;

use function Onion\Framework\Loop\coroutine;
use InvalidArgumentException;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Socket;
use Onion\Framework\Loop\Types\Operation;

use Onion\Framework\Test\TestCase;

class SocketTest extends TestCase
{
    private $resource;

    protected function setUp(): void
    {
        $this->resource = stream_socket_server('tcp://127.0.0.1:12345');
        $this->name = stream_get_meta_data($this->resource)['uri'] ?? null;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    public function testConnectionAcceptance()
    {
        $socket = new Socket($this->resource);
        $socket->unblock();
        coroutine(function () {
            stream_socket_client('tcp://127.0.0.1:12345');
        });
        $connection = $socket->accept();
        $this->assertInstanceOf(ResourceInterface::class, $connection);
        $connection->close();
    }

    public function testDataTransfer()
    {
        $fp = stream_socket_client('tcp://1.1.1.1:80');
        $socket = new Socket($fp);
        $socket->unblock();
        $socket->wait(Operation::WRITE);
        $socket->write("HTTP/1.1 GET /\r\n\r\n");
        $socket->wait(Operation::READ);
        $this->assertNotSame('', $socket->read(1024));

        $socket->close();
    }

    public function testAcceptOnInvalidResource()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Expected argument to be resource/i');

        $fp = fopen('php://temp', 'r');
        $socket = new Socket($fp);
        $socket->unblock();

        $socket->accept(1);
    }
}
