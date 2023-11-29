<?php

namespace React\Mysql\Commands;

use Evenement\EventEmitterInterface;

/**
 * @internal
 */
interface CommandInterface extends EventEmitterInterface
{
    public function getId();
}
