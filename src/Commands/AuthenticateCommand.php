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
        if ($authPlugin !== null && $authPlugin !== 'mysql_native_password' && $authPlugin !== 'caching_sha2_password') {
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
            . $buffer->buildStringLen($authPlugin === 'caching_sha2_password' ? $this->authCachingSha2Password($scramble) : $this->authMysqlNativePassword($scramble))
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

    /**
     * @param string $scramble
     * @return string
     * @throws \BadFunctionCallException if SHA256 hash algorithm is not available if ext-hash is missing, only possible in PHP < 7.4
     */
    private function authCachingSha2Password($scramble)
    {
        if ($this->passwd === '') {
            return '';
        }

        if (\PHP_VERSION_ID < 70100 || !\function_exists('hash')) {
            throw new \UnexpectedValueException('Requires PHP 7.1+ with ext-hash for authentication plugin "caching_sha2_password" requested by server');
        }

        \assert(\in_array('sha256', \hash_algos(), true));
        return ($hash1 = \hash('sha256', $this->passwd, true)) ^ \hash('sha256', \hash('sha256', $hash1, true) . $scramble, true);
    }

    /**
     * @param string $scramble
     * @param string $pubkey
     * @return string
     * @throws \UnexpectedValueException if encryption fails (e.g. missing ext-openssl or invalid public key)
     */
    public function authSha256($scramble, $pubkey)
    {
        if (!\function_exists('openssl_public_encrypt')) {
            throw new \UnexpectedValueException('Requires ext-openssl for authentication plugin "caching_sha2_password" requested by server');
        }

        $ret = @\openssl_public_encrypt(
            $this->passwd . "\x00" ^ \str_pad($scramble, \strlen($this->passwd) + 1, $scramble),
            $auth,
            $pubkey,
            \OPENSSL_PKCS1_OAEP_PADDING
        );

        // unlikely: openssl_public_encrypt() may return false if the public key sent by the server is invalid
        if ($ret === false) {
            throw new \UnexpectedValueException('Failed to encrypt password with public key');
        }

        return $auth;
    }
}
