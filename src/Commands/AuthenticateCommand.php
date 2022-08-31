<?php

namespace React\MySQL\Commands;

use React\MySQL\Io\Buffer;
use React\MySQL\Io\Constants;

/**
 * @internal
 * @link https://dev.mysql.com/doc/internals/en/connection-phase-packets.html#packet-Protocol::HandshakeResponse
 */
class AuthenticateCommand extends AbstractCommand
{
    private $user;
    private $passwd;
    private $dbname;

    private $maxPacketSize = 0x1000000;

    /**
     * @var int
     * @link https://dev.mysql.com/doc/internals/en/character-set.html#packet-Protocol::CharacterSet
     */
    private $charsetNumber;

    /**
     * Mapping from charset name to internal charset ID
     *
     * Note that this map currently only contains ASCII-compatible charset encodings
     * because of quoting rules as defined in the `Query` class.
     *
     * @var array<string,int>
     * @see self::$charsetNumber
     * @see \React\MySQL\Io\Query::$escapeChars
     */
    private static $charsetMap = [
        'latin1' => 8,
        'latin2' => 9,
        'ascii' => 11,
        'latin5' => 30,
        'utf8' => 33,
        'latin7' => 41,
        'utf8mb4' => 45,
        'binary' => 63
    ];

    /**
     * @param string $user
     * @param string $passwd
     * @param string $dbname
     * @param string $charset
     * @throws \InvalidArgumentException for invalid/unknown charset name
     */
    public function __construct(
        $user,
        #[\SensitiveParameter]
        $passwd,
        $dbname,
        $charset
    ) {
        if (!isset(self::$charsetMap[$charset])) {
            throw new \InvalidArgumentException('Unsupported charset selected');
        }

        $this->user = $user;
        $this->passwd = $passwd;
        $this->dbname = $dbname;
        $this->charsetNumber = self::$charsetMap[$charset];
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
