<?php

namespace React\MySQL\Commands;

/**
 * @internal
 */
class AuthenticateCommand extends AbstractCommand
{
    public function getId()
    {
        return self::INIT_AUTHENTICATE;
    }

    public function buildPacket()
    {
    }
}
