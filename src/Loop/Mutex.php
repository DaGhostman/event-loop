<?php

declare(strict_types=1);

namespace Onion\Framework\Loop;

use Closure;
use Onion\Framework\Loop\Interfaces\MutexInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Types\Lock as LockType;

use function Onion\Framework\Loop\signal;

/**
 * @internal
 */
class Mutex implements MutexInterface
{
    private static \WeakMap $mutexes;

    private function __construct()
    {
    }

    private static function getStorage(): \WeakMap
    {
        if (!isset(static::$mutexes)) {
            static::$mutexes = new \WeakMap();
        }

        return static::$mutexes;
    }

    private static function lock(object $target, TaskInterface $owner, LockType $type): bool
    {
        $storage = static::getStorage();

        if (!isset($storage[$target])) {
            $storage[$target] = [$owner, $type, 1];

            return true;
        } elseif (
            ($type === LockType::EXCLUSIVE && $storage[$target][0] === $owner) ||
            ($type === LockType::SHARED && $storage[$target][1] === LockType::SHARED)
        ) {
            $storage[$target][2]++;

            return true;
        }

        return false;
    }

    private static function unlock(object $target, TaskInterface $owner): bool
    {
        $storage = static::getStorage();
        if (
            isset($storage[$target]) &&
            (
                ($storage[$target][0] === $owner && $storage[$target][1] === LockType::EXCLUSIVE) ||
                ($storage[$target][1] === LockType::SHARED)
            )
        ) {
            $storage[$target][2]--;

            if ($storage[$target][2] === 0) {
                unset($storage[$target]);
            }

            return true;
        }

        return false;
    }

    public static function acquire(
        object $target,
        LockType $type = LockType::EXCLUSIVE,
    ): bool {
        return signal(
            fn (Closure $resume, TaskInterface $task) => $resume(static::lock($target, $task, $type))
        );
    }

    public static function release(
        object $target,
    ): bool {
        return signal(
            fn (Closure $resume, TaskInterface $task) => $resume(static::unlock($target, $task))
        );
    }
}
