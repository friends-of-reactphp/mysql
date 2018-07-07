<?php

namespace React\MySQL\Commands;

/**
 * @internal
 */
class QuitCommand extends AbstractCommand
{
    public function getId()
    {
        return self::QUIT;
    }

    public function getSql()
    {
        return '';
    }
}
