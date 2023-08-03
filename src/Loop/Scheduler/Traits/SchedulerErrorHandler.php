<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Scheduler\Traits;

use Closure;
use LogicException;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Throwable;

trait SchedulerErrorHandler
{
    private array $errorHandlers = [];

    public function addErrorHandler(Closure $handler): void
    {
        $this->errorHandlers[] = $handler;
    }

    public function removeHandler(Closure $handler): void
    {
        $key = array_search($handler, $this->errorHandlers, true);

        if ($key !== false) {
            return;
        }

        unset($this->errorHandlers[$key]);
    }

    protected function triggerErrorHandlers(Throwable $ex): void
    {
        assert(
            $this instanceof SchedulerInterface,
            new LogicException(
                'Using SchedulerErrorHandler trait in a class that does not '.
                    'implement SchedulerInterface is invalid'
            ),
        );

        foreach ($this->errorHandlers as $handler) {
            try {
                $handler($ex);
            } catch (Throwable $ex) {
                $this->triggerErrorHandlers($ex);
                break;
            }
        }
    }
}
