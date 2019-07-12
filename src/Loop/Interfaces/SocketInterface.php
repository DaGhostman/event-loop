<?php
namespace Onion\Framework\Loop\Interfaces;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Signal;

interface SocketInterface extends ResourceInterface
{
    public function accept(): Signal;
}
