<?php
namespace Onion\Framework\Loop\Interfaces;

use Onion\Framework\Loop\Interfaces\AsyncResourceInterface;
use Onion\Framework\Loop\Signal;

interface SocketInterface extends AsyncResourceInterface
{
    public function accept(): Signal;
}
