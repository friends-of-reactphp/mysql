<?php

namespace React\Mysql\Commands;

use React\Mysql\Io\Buffer;
use React\Mysql\Io\Constants;

/**
 * @internal
 * @link https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_connection_phase_packets_protocol_handshake_response.html#sect_protocol_connection_phase_packets_protocol_handshake_response41
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
     * @see \React\Mysql\Io\Query::$escapeChars
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

    /**
     * @param string $scramble
     * @param ?string $authPlugin
     * @param Buffer $buffer
     * @return string
     * @throws \UnexpectedValueException for unsupported authentication plugin
     */
    public function authenticatePacket($scramble, $authPlugin, Buffer $buffer)
    {
        if ($authPlugin !== null && $authPlugin !== 'mysql_native_password') {
            throw new \UnexpectedValueException('Unknown authentication plugin "' . addslashes($authPlugin) . '" requested by server');
        }

        $clientFlags = Constants::CLIENT_LONG_PASSWORD |
            Constants::CLIENT_LONG_FLAG |
            Constants::CLIENT_LOCAL_FILES |
            Constants::CLIENT_PROTOCOL_41 |
            Constants::CLIENT_INTERACTIVE |
            Constants::CLIENT_TRANSACTIONS |
            Constants::CLIENT_SECURE_CONNECTION |
            Constants::CLIENT_CONNECT_WITH_DB;

        if ($authPlugin !== null) {
            $clientFlags |= Constants::CLIENT_PLUGIN_AUTH;
        }

        return pack('VVc', $clientFlags, $this->maxPacketSize, $this->charsetNumber)
            . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            . $this->user . "\x00"
            . $buffer->buildStringLen($this->authMysqlNativePassword($scramble))
            . $this->dbname . "\x00"
            . ($authPlugin !== null ? $authPlugin . "\0" : '');
    }

    /**
     * @param string $scramble
     * @return string
     */
    private function authMysqlNativePassword($scramble)
    {
        if ($this->passwd === '') {
            return '';
        }

        return \sha1($scramble . \sha1($hash1 = \sha1($this->passwd, true), true), true) ^ $hash1;
    }
}
