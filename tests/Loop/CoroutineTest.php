<?php

namespace Tests;

use Onion\Framework\Test\TestCase;

use function Onion\Framework\Loop\{coroutine, scheduler, signal, tick};

class CoroutineTest extends TestCase
{
    public function testSimpleRun()
    {
        $this->expectOutputString('x');

        coroutine(function () {
            echo 'x';
        });
    }

    public function testExceptionHandling()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('received');

        coroutine(function () {
            throw new \RuntimeException('received');
        })->sync();
    }

    public function testExternalExceptionHandling()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('received');

        coroutine(fn() => signal(fn($resume) => $resume()))
            ->throw(new \RuntimeException('received'));
    }

    public function testSignalExceptionHandling()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('received');

        coroutine(function () {
            try {
                signal(
                    function () {
                        throw new \RuntimeException('ex');
                    }
                );
            } catch (\Throwable $ex) {
                throw new \RuntimeException('received');
            }
        });
    }

    public function testCoroutineSignals()
    {
        coroutine(function () {
            $this->assertSame('x', signal(fn($resume) => $resume('x')));
        });
    }

    public function testCoroutineSuspension()
    {
        $this->expectOutputString('x');
        $coroutine = coroutine(function () {
            echo 'x';
        });
        tick();
        $coroutine->suspend();
        // tick();
    }

    public function testCoroutineStatus()
    {
        coroutine(function () {
            $coroutine = coroutine(fn() => signal(fn() => null));
            tick();
            $this->assertTrue($coroutine->isPaused());
            $this->assertFalse($coroutine->isFinished());
            $this->assertFalse($coroutine->isKilled());
        });
    }

    public function testHandlingOfPausedCoroutine()
    {
        $this->expectOutputString('test');
        $coroutine = coroutine(function () {
            signal(fn() => null);

            echo 'test';
        });

        coroutine(function () use ($coroutine) {
            for ($i = 0; $i < 5; $i++) {
                tick();
                scheduler()->schedule($coroutine);
            }
            $coroutine->resume();
        });
    }

    public function testCoroutineCooperation()
    {
        $i = 0;
        $j = 0;
        coroutine(function () use (&$i) {
            tick();
            $i++;
        });
        coroutine(function () use (&$j) {
            tick();
            $j++;
        });
        tick();

        coroutine(function () use (&$i, &$j) {
            $this->assertSame(1, $i);
            $this->assertSame(1, $j);
        });
    }
}
