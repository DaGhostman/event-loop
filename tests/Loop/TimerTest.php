<?php

namespace Tests;

use Onion\Framework\Loop\Timer;
use Onion\Framework\Test\TestCase;

class TimerTest extends TestCase
{
    public function testExecutionAfter()
    {
        Timer::after(fn() => $this->assertTrue(true), 0);
    }

    public function testExecutionInterval()
    {
        $count = 0;
        $this->expectOutputString('xxxxx');

        $timer = Timer::interval(function () use (&$count, &$timer) {
            if ($count === 5) {
                $timer->stop();
            } else {
                echo 'x';
                $count++;
            }
        }, 1);
    }
}
