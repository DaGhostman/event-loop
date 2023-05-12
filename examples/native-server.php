<?php
use Onion\Framework\Loop\Scheduler\Interfaces\NetworkedSchedulerInterface;
use function Onion\Framework\Loop\scheduler;

define('EVENT_LOOP_TRACE_TASKS', false);

require __DIR__ . '/../vendor/autoload.php';

$scheduler = scheduler();

if ($scheduler instanceof NetworkedSchedulerInterface) {
    $address = $scheduler->listen('0.0.0.0', 1234, function (string $data) {
        return "HTTP/1.1 200 OK\r\nKeep-Alive: timeout=5; max=1000\r\n\r\n" . $data;
    });

    var_dump($address);
}

