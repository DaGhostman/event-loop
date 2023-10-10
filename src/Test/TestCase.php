<?php

namespace Onion\Framework\Test;

use Onion\Framework\Loop\Scheduler\Select as Scheduler;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

use function Onion\Framework\Loop\{coroutine, scheduler};

class TestCase extends PhpUnitTestCase
{
    private string $realTestName;

    final public function setName(string $name): void
    {
        parent::setName($name);
        $this->realTestName = $name;
    }

    final public function runAsyncTest(mixed ...$args): void
    {
        parent::setName($this->realTestName);

        scheduler(new Scheduler());
        coroutine($this->{$this->realTestName}(...), $args);
        scheduler()->start();
    }

    final protected function runTest(): void
    {
        parent::setName('runAsyncTest');
        parent::runTest();
    }
}
