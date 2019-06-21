<?php
namespace Onion\Framework\Loop;

class Result
{
    private $value;
    public function __construct($value)
    {
        $this->value = $value;
    }

    public function getValue()
    {
        return $this->value;
    }
}
