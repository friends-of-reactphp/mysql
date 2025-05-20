<?php

namespace React\Tests\Mysql\Commands;

use PHPUnit\Framework\TestCase;
use React\Mysql\Commands\AuthenticateCommand;
use React\Mysql\Io\Buffer;

class AuthenticateCommandTest extends TestCase
{
    /**
     * @doesNotPerformAssertions
     */
    public function testCtorWithKnownCharset()
    {
        new AuthenticateCommand('Alice', 'secret', '', 'utf8');
    }

    public function testCtorWithUnknownCharsetThrows()
    {
        if (method_exists($this, 'expectException')) {
            $this->expectException('InvalidArgumentException');
        } else {
            // legacy PHPUnit < 5.2
            $this->setExpectedException('InvalidArgumentException');
        }
        new AuthenticateCommand('Alice', 'secret', '', 'utf16');
    }

    public function testAuthenticatePacketWithEmptyPassword()
    {
        $command = new AuthenticateCommand('root', '', 'test', 'utf8mb4');

        $data = $command->authenticatePacket('scramble', null, new Buffer());

        $this->assertEquals("\x8d\xa6\0\0\0\0\0\x01\x2d" . str_repeat("\0", 23) . "root\0" . "\0" . "test\0", $data);
    }

    public function testAuthenticatePacketWithMysqlNativePasswordAuthPluginAndEmptyPassword()
    {
        $command = new AuthenticateCommand('root', '', 'test', 'utf8mb4');

        $data = $command->authenticatePacket('scramble', 'mysql_native_password', new Buffer());

        $this->assertEquals("\x8d\xa6\x08\0\0\0\0\x01\x2d" . str_repeat("\0", 23) . "root\0" . "\0" . "test\0" . "mysql_native_password\0", $data);
    }

    public function testAuthenticatePacketWithCachingSha2PasswordAuthPluginAndEmptyPassword()
    {
        $command = new AuthenticateCommand('root', '', 'test', 'utf8mb4');

        $data = $command->authenticatePacket('scramble', 'caching_sha2_password', new Buffer());

        $this->assertEquals("\x8d\xa6\x08\0\0\0\0\x01\x2d" . str_repeat("\0", 23) . "root\0" . "\0" . "test\0" . "caching_sha2_password\0", $data);
    }

    public function testAuthenticatePacketWithSecretPassword()
    {
        $command = new AuthenticateCommand('root', 'secret', 'test', 'utf8mb4');

        $data = $command->authenticatePacket('scramble', null, new Buffer());

        $this->assertEquals("\x8d\xa6\0\0\0\0\0\x01\x2d" . str_repeat("\0", 23) . "root\0" . "\x14\x18\xd8\x8d\x74\x77\x2c\x27\x22\x89\xe1\xcd\xbc\x4b\x5f\x77\x08\x18\x3c\x6e\xba" . "test\0", $data);
    }

    /**
     * @requires PHP 7.1
     * @requires function hash
     */
    public function testAuthenticatePacketWithCachingSha2PasswordWithSecretPasswordHashed()
    {
        $command = new AuthenticateCommand('root', 'secret', 'test', 'utf8mb4');

        $data = $command->authenticatePacket('scramble', 'caching_sha2_password', new Buffer());

        $this->assertEquals("\x8d\xa6\x08\0\0\0\0\x01\x2d" . str_repeat("\0", 23) . "root\0" . "\x20\x7a\x62\x89\x95\x53\xed\xdd\xa4\x11\x2d\x28\x9a\x02\x72\x12\xbb\x4c\xdd\xfd\xd3\x08\xfe\xc3\x6a\x85\xf1\xe9\x4a\xdb\xcf\x8b\xf3" . "test\0" . "caching_sha2_password\0", $data);
    }

    public function testAuthenticatePacketWithUnknownAuthPluginThrows()
    {
        $command = new AuthenticateCommand('root', 'secret', 'test', 'utf8mb4');

        if (method_exists($this, 'expectException')) {
            $this->expectException('UnexpectedValueException');
            $this->expectExceptionMessage('Unknown authentication plugin "mysql_old_password" requested by server');
        } else {
            // legacy PHPUnit < 5.2
            $this->setExpectedException('UnexpectedValueException', 'Unknown authentication plugin "mysql_old_password" requested by server');
        }
        $command->authenticatePacket('scramble', 'mysql_old_password', new Buffer());
    }

    /**
     * @requires function openssl_public_encrypt
     */
    public function testAuthSha256WithValidPublicKeyReturnsScrambledDataThatCanBeDecryptedByReceiverWithPrivateKey()
    {
        $command = new AuthenticateCommand('root', 'secret', 'test', 'utf8mb4');

        $key = openssl_pkey_new();

        $encrypted = $command->authSha256('scramble', openssl_pkey_get_details($key)['key']);

        $decrypted = '';
        $ok = openssl_private_decrypt($encrypted, $decrypted, $key, OPENSSL_PKCS1_OAEP_PADDING);

        $this->assertTrue($ok);
        $this->assertEquals("secret\0", $decrypted ^ "scramble");
    }

    /**
     * @requires function openssl_public_encrypt
     */
    public function testAuthSha256WithPasswordLongerThanScrambleLengthReturnsScrambledDataThatCanBeDecryptedByReceiverWithPrivateKey()
    {
        $command = new AuthenticateCommand('root', '012345678901234567890123456789', 'test', 'utf8mb4');

        $key = openssl_pkey_new();

        $encrypted = $command->authSha256('scramble', openssl_pkey_get_details($key)['key']);

        $decrypted = '';
        $ok = openssl_private_decrypt($encrypted, $decrypted, $key, OPENSSL_PKCS1_OAEP_PADDING);

        $this->assertTrue($ok);
        $this->assertEquals("012345678901234567890123456789\0", $decrypted ^ "scramblescramblescramblescramblescramble");
    }

    /**
     * @requires function openssl_public_encrypt
     */
    public function testAuthSha256WithInvalidPublicKeyThrows()
    {
        $command = new AuthenticateCommand('root', 'secret', 'test', 'utf8mb4');

        if (method_exists($this, 'expectException')) {
            $this->expectException('UnexpectedValueException');
            $this->expectExceptionMessage('Failed to encrypt password with public key');
        } else {
            // legacy PHPUnit < 5.2
            $this->setExpectedException('UnexpectedValueException', 'Failed to encrypt password with public key');
        }
        $command->authSha256('scramble', 'invalid pubkey');
    }
}
