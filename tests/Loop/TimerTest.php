<?php

namespace Tests;

use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Timer;
use Onion\Framework\Test\TestCase;

class TimerTest extends TestCase
{
    public function testExecutionAfter()
    {
        Timer::after(fn () => $this->assertTrue(true), 0);
    }

    public function testExecutionInterval()
    {
        $count = 0;
        $this->expectOutputString('xxxxx');

        Timer::interval(function () use (&$count) {
            if ($count === 5) {
                Coroutine::task()->kill();
            } else {
                echo 'x';
                $count++;
            }
        }, 1);
    }
}
