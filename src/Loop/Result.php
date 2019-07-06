<?php

namespace Onion\Framework\Loop;

class Result
{
    protected $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function getValue() {
        return $this->value;
    }
}
