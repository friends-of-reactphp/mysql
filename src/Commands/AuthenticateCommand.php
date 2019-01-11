<?php

namespace React\MySQL\Commands;

use React\MySQL\Io\Buffer;
use React\MySQL\Io\Constants;

/**
 * @internal
 */
class AuthenticateCommand extends AbstractCommand
{
    private $user;
    private $passwd;
    private $dbname;

    private $maxPacketSize = 0x1000000;
    private $charsetNumber = 0x21;

    public function __construct($user, $passwd, $dbname)
    {
        $this->user = $user;
        $this->passwd = $passwd;
        $this->dbname = $dbname;
    }

    public function getId()
    {
        return 0;
    }

    public function authenticatePacket($scramble, Buffer $buffer)
    {
        $clientFlags = Constants::CLIENT_LONG_PASSWORD |
            Constants::CLIENT_LONG_FLAG |
            Constants::CLIENT_LOCAL_FILES |
            Constants::CLIENT_PROTOCOL_41 |
            Constants::CLIENT_INTERACTIVE |
            Constants::CLIENT_TRANSACTIONS |
            Constants::CLIENT_SECURE_CONNECTION |
            Constants::CLIENT_CONNECT_WITH_DB;

        return pack('VVc', $clientFlags, $this->maxPacketSize, $this->charsetNumber)
            . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            . $this->user . "\x00"
            . $this->getAuthToken($scramble, $this->passwd, $buffer)
            . $this->dbname . "\x00";
    }

    public function getAuthToken($scramble, $password, Buffer $buffer)
    {
        if ($password === '') {
            return "\x00";
        }
        $token = \sha1($scramble . \sha1($hash1 = \sha1($password, true), true), true) ^ $hash1;

        return $buffer->buildStringLen($token);
    }
}
