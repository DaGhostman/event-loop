<?php

use Onion\Framework\Loop\Channels\BufferedChannel;
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Timer;

use function Onion\Framework\Loop\channel;
use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;
use function Onion\Framework\Loop\tick;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

function startReceiver(int &$i, BufferedChannel $channel)
{
    return coroutine(function (BufferedChannel $channel) use (&$i) {
        echo 'Starting receiver' . PHP_EOL;
        while (!$channel->isClosed()) {
            echo "Receiving {$channel->recv()}\n";
            tick();
        }
        echo 'Ending Receiver' . PHP_EOL;
    }, [$channel]);
}

coroutine(function () {
    try {
        $bufferedChannel = channel(3);
        $i = 0;
        coroutine(function (BufferedChannel $channel) use (&$i) {
            echo 'Starting sender' . PHP_EOL;
            for (; $i < 100; $i++) {
                $channel->send($i);
                echo "{$i} sent\n";
            }
            $channel->close();
            echo 'Ending sender' . PHP_EOL;
        }, [$bufferedChannel]);
    } catch (\Throwable $ex) {
        var_dump($ex->getMessage());
    }

    startReceiver($i, $bufferedChannel);
    startReceiver($i, $bufferedChannel);
    startReceiver($i, $bufferedChannel);
    startReceiver($i, $bufferedChannel);
    startReceiver($i, $bufferedChannel);
    startReceiver($i, $bufferedChannel);
    startReceiver($i, $bufferedChannel);

    // $unbufferedChannel = channel();
});
scheduler()->start();
