<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Debug;
use Error;
use Exception;
use Fiber;
use Onion\Framework\Loop\Signal;
use Onion\Framework\Loop\Task;
use ReflectionFiber;
use ReflectionFunction;
use ReflectionProperty;
use Throwable;

use function Onion\Framework\Loop\scheduler;

class TraceableTask extends Task
{
    private const ALIASED_SOURCES = [
        'src/functions.php:168-172' => ['coroutine', '{internal}'],
        'src/functions.php:267-267' => ['suspend', '{internal}'],
        'src/functions.php:203-216' => ['signal', '{internal}'],
        'src/functions.php:91-104' => ['write', '{internal}'],
        'src/functions.php:51-58' => ['read', '{internal}'],
        'src/functions.php:235-242' => ['with', '{internal}'],
        'src/functions.php:407-407' => ['sleep', '{internal}'],
        'src/functions.php:424-424' => ['delay', '{internal}'],

        'vendor/onion/event-loop/src/functions.php:168-172' => ['coroutine', '{internal}'],
        'vendor/onion/event-loop/src/functions.php:267-267' => ['suspend', '{internal}'],
        'vendor/onion/event-loop/src/functions.php:203-216' => ['signal', '{internal}'],
        'vendor/onion/event-loop/src/functions.php:91-104' => ['write', '{internal}'],
        'vendor/onion/event-loop/src/functions.php:51-58' => ['read', '{internal}'],
        'vendor/onion/event-loop/src/functions.php:235-242' => ['with', '{internal}'],
        'vendor/onion/event-loop/src/functions.php:407-407' => ['sleep', '{internal}'],
        'vendor/onion/event-loop/src/functions.php:424-424' => ['delay', '{internal}'],
    ];

    private readonly array $trace;
    private readonly string $name;
    private readonly string $source;
    private readonly float $start;

    private array $ticks = [];

    private static ReflectionProperty $exceptionTraceProperty;
    private static ReflectionProperty $errorTraceProperty;

    private ?bool $registered = false;


    public function __construct(private readonly Fiber $coroutine, mixed $args)
    {
        $function = new ReflectionFunction(\Closure::fromCallable((new ReflectionFiber($this->coroutine))->getCallable()));
        $name = "{$function->getNamespaceName()}\\{$function->getName()}";
        $source = "{$function->getFileName()}:{$function->getStartLine()}-{$function->getEndLine()}";

        if ($function->getClosureThis() instanceof Signal) {
            $function = (new ReflectionFunction($function->getClosureScopeClass()
                ->getProperty('callback')
                ->getValue($function->getClosureThis())))
                ->getClosureUsedVariables()['fn'] ?? null;

            if ($function) {
                $function = new ReflectionFunction($function);
            }

            $name = "{$function->getNamespaceName()}\\{$function->getName()}";
            $source = "{$function->getFileName()}:{$function->getStartLine()}-{$function->getEndLine()}";

            foreach (self::ALIASED_SOURCES as $path => $alias) {
                if (substr($source, -strlen($path)) === $path) {
                    [$name, $source] = $alias;
                    break;
                }
            }
        }

        $this->name = $name;
        $this->source = $source;

        $this->trace = debug_backtrace();
        $this->start = hrtime(true);

        try {
            parent::__construct($coroutine, $args);
        } catch (Throwable $ex) {
            throw static::setTrace($ex, $this->trace);
        }
    }

    public function run(): mixed
    {
        if (!$this->registered) {
            $scheduler = scheduler();

            if ($scheduler instanceof TraceableScheduler) {
                $scheduler->stat($this->name, $this->source, [
                    'ticks' => $this->getIterations(),
                    'duration' => $this->getDuration(),
                    'latency' => $this->getDelay(),
                    'average' => $this->getAverageDuration(),
                    'memory' => $this->getConsumedMemory(),
                ]);
            }
            $this->registered = true;
        }

        $memory = memory_get_usage();
        $start = hrtime(true);
        $end = 0;

        try {
            $result = parent::run();

            $end = hrtime(true);

            return $result;
        } catch (Throwable $ex) {
            $end = hrtime(true);
            throw static::setTrace($ex, $this->trace);
        } finally {
            $this->ticks[] = [$start, $end, memory_get_usage() - $memory];
        }
    }

    public function throw(Throwable $ex): bool
    {
        return parent::throw(static::setTrace($ex, $this->trace));
    }

    public function getIterations(): int
    {
        return count($this->ticks);
    }

    public function getDuration(): float
    {
        return array_reduce($this->ticks, fn ($carry, $item) => $carry + ($item[1] - $item[0]), 0) / 1e6;
    }

    public function getAverageDuration(): float
    {
        $iterations = $this->getIterations();
        if ($iterations === 0) return 0;

        return $this->getDuration() / $this->getIterations();
    }

    public function getDelay(): float
    {
        if (count($this->ticks) === 0) {
            return 0;
        }

        return ($this->ticks[0][0] - $this->start) / 1e6;
    }

    public function getConsumedMemory(): int
    {
        return array_reduce($this->ticks, fn ($carry, $item) => $carry + $item[2], 0);
    }

    private static function getErrorProperty(Throwable $ex): ReflectionProperty
    {
        if (!isset(static::$errorTraceProperty) && $ex instanceof Error) {
            static::$errorTraceProperty = new ReflectionProperty(
                Error::class,
                'trace'
            );
            static::$errorTraceProperty->setAccessible(true);

            return static::$errorTraceProperty;
        }

        if (!isset(static::$exceptionTraceProperty) && $ex instanceof Exception) {
            static::$exceptionTraceProperty = new ReflectionProperty(
                Exception::class,
                'trace'
            );
            static::$exceptionTraceProperty->setAccessible(true);

            return static::$exceptionTraceProperty;
        }

        return $ex instanceof Error ?
            static::$errorTraceProperty : static::$exceptionTraceProperty;
    }

    private static function setTrace(Throwable $ex, array $trace): Throwable
    {
        if (!EVENT_LOOP_TRACE_TASKS) {
            return $ex;
        }

        (static::getErrorProperty($ex))
            ->setValue(
                $ex,
                array_filter($trace, fn ($item) => (
                        (isset($item['file']) && stripos($item['file'], 'onion' . DIRECTORY_SEPARATOR .  'event-loop') === -1) ||
                        (isset($item['function']) && stripos($item['function'], 'Onion\Framework\Loop\\') === -1)
                    )
                )
            );

        return $ex;
    }

    public function __destruct()
    {
        if (!$this->registered) return;
        $scheduler = scheduler();

        if ($scheduler instanceof TraceableScheduler) {
            $scheduler->stat($this->name, $this->source, [
                'ticks' => $this->getIterations(),
                'duration' => $this->getDuration(),
                'latency' => $this->getDelay(),
                'average' => $this->getAverageDuration(),
                'memory' => $this->getConsumedMemory(),
            ]);
        }
    }
}
