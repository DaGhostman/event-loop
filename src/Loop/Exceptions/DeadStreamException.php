<?php
namespace Onion\Framework\Loop\Exceptions;

use Throwable;

class DeadStreamException extends BadStreamOperation implements Throwable
{
    public function __construct(string $operation, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Unable to '{$operation}' on a dead stream", $code, $previous);
    }
}
