<?php

namespace Bixuehujin\React\MySQL\Commands;

use Bixuehujin\React\MySQL\Command;

class QuitCommand extends Command
{
    public function getId()
    {
        return self::QUIT;
    }

    public function buildPacket()
    {
    }

    public function getSql()
    {
        return '';
    }
}
