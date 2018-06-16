<?php

namespace React\MySQL\Commands;

/**
 * @internal
 */
class PingCommand extends AbstractCommand
{
    public function getId()
    {
        return self::PING;
    }

    public function buildPacket()
    {
    }

    public function getSql()
    {
        return '';
    }
}
