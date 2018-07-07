<?php

namespace React\MySQL\Commands;

use Evenement\EventEmitterInterface;

/**
 * @internal
 */
interface CommandInterface extends EventEmitterInterface
{
    public function getId();
}
