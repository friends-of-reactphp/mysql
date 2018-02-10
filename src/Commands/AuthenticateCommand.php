<?php

namespace Bixuehujin\React\MySQL\Commands;

use Bixuehujin\React\MySQL\Command;

class AuthenticateCommand extends Command
{
    public function getId()
    {
        return self::INIT_AUTHENTICATE;
    }

    public function buildPacket()
    {
    }
}
