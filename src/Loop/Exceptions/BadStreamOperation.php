<?php
namespace Onion\Framework\Loop\Exceptions;

use LogicException;
use Throwable;

class BadStreamOperation extends LogicException implements Throwable
{
    public function __construct(string $operation, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Operation '{$operation}' not allowed on stream", $code, $previous);
    }
}
