<?php

use function Onion\Framework\Loop\channel;
use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;

use function Onion\Framework\Loop\tick;

use Onion\Framework\Loop\Channels\AbstractChannel;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

function startReceiver(int $i, AbstractChannel &$channel)
{
    return coroutine(function (AbstractChannel $channel) use (&$i) {
        echo 'Starting receiver' . PHP_EOL;
        while ([$value, $ok] = $channel->recv()) {
            if (!$ok) break;
            echo "<< #{$i}: {$value}\n";
            tick();
        }
        echo "Ending Receiver #{$i}" . PHP_EOL;
    }, [$channel]);
}

coroutine(function () {
    $bufferedChannel = channel();
    coroutine(function (AbstractChannel $channel) use (&$i) {
        echo 'Starting sender' . PHP_EOL;
        for ($i = 0; $i < 100; $i++) {
            $channel->send($i);
            echo ">> {$i}\n";
        }
        $channel->close();
        echo 'Ending sender' . PHP_EOL;
    }, [$bufferedChannel]);

    startReceiver(1, $bufferedChannel);
    startReceiver(2, $bufferedChannel);
    startReceiver(3, $bufferedChannel);
    startReceiver(4, $bufferedChannel);
    startReceiver(5, $bufferedChannel);
    startReceiver(6, $bufferedChannel);
    startReceiver(7, $bufferedChannel);
    startReceiver(8, $bufferedChannel);
    startReceiver(9, $bufferedChannel);
    startReceiver(10, $bufferedChannel);
    startReceiver(11, $bufferedChannel);
});
scheduler()->start();
