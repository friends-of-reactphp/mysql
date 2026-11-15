<?php

namespace React\Tests\Mysql\Commands;

use PHPUnit\Framework\TestCase;
use React\Mysql\Commands\AuthenticateCommand;
use React\Mysql\Io\Buffer;

class AuthenticateCommandTest extends TestCase
{
    // static key as openssl_pkey_new() fails with some openssl.cnf files on legacy PHP < 5.6 and legacy OpenSSL < 1.1.1
    const PRIVATE_KEY = '-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDx0CQ68rLWOTeN
1S36TWNcMSV62TggJAW2Ff2aGeJD9dQbW7iNuknr8tovDRmc2i4BiMH9OHx0mb7s
GN1CzEuBpwfibQHo3Eg87m9X8KC+aFtOSf6CI9eaP2B4qN4b06b2Zic9liXg4BDL
duvA5qQbE5DrXh2m0HlL2z7gGhBPrJ6wVncNFYXHP8Wn1ki+LQa9zm0Cn3Tujus7
XwZ5FA1OQrLuE44w8wVOfG10u2rkbWQb41qWOZtt9eN4Iot6OHdv6kHRr7Nx7jZ4
DttRsGoFqQAu2dWZ55B8Nlr+iMPVyfAxsstgBLgpdMue7IVA3BmaSZsZupgPeEc8
2w892Kl7AgMBAAECggEAVTuESbLaDmBEkJ2fgyZb61b3WwHNoqaPzMqbhQGymzm+
9ZWyy2HqRFxpQ0RezsaoiKnnlpWeaIr4nMd21eCkjKKPSOQbVDSw0alJXbnXLsYh
yMqUBtMTX68PFfiPsUc9BoSjODYAS+8ufEkCeA/TsCeJYSzfTrkUyAQTeXI2ZZ5H
NfBTFyo/yB59HY5NvowItFMBb34IyPhfzJCHtt1ZrNRhhGWfV2FNxAczdhGTdXfH
cfkepzhRUvEQ93ju2agt24boCi2zCB7ggYcY+/MYUgPx6QDixXJZm7DHt0a9gbK/
AruBnsDwWJWcwSE2Q8Ij81rk6TthA/Lz8JHuNpXY4QKBgQD8rmyclOFo4UJ80Juk
JHaL5Xr57Sh/iC10qAhQ993Ivly4kXxNN9lLRaJjdeBYsJ/OLsNumgYAw3RJdkGO
J+Eo05pgFyvnLoanZX+LdTAsTDZrqeepo6zPKr7Ypj5cn59XGQ0WthMAJ77isiF3
wQUJbpLjqWeoRMNOgQSF8GLf5wKBgQD0/SzpagDhVpqu4rd7MA6dPYhFLPcFoqfC
rZPsnMXX7o/0JaHlSiBztsm7TCdu3E5Z7gFIla4URMn960ABU66B8mP3tCgRWh/I
hLYdx7Wy6wEkn1XIEA+W1BPz6kh9BD/HiWRzE4HuVlAdmg2vmYviaBpGzCBJvimD
iHv8VKQHTQKBgGds2aYJxm3Qbg96r4ozJpbCemCcMwxoZ/qs4KKLoisNyBP/8LoG
lxmZSgud7xM+UFrrhzDZOIXNeuaq0dBnAGFeo3ybX2wD/je8csVZVTt8gnX1GI62
IQC3BWDWQwZUi7rx1QqrwgE5Q4e008AZsH8Y1GT9LSGR68NRSdGtADuXAoGAAoIh
Fpa2QZhgZw/9Lu60B5ocrhCiytT6gLzEOfT09ZwEr0w/VximMmkZsag68yCgUL1m
F/coXB7ELqxc8GaJ79yqd1vJ2Tuh/ayWLMHhs15Cmbxj5XN8pV+wgJvSeC019fYL
KD534xfRJCxynWymCQBl3Hm0juy6ghVAp7m6IDUCgYEAjoRYr+LfHrnJMx7PtLKH
hg6THWCKi85wdnGZrvgAdaKjk5O5pFCd9AV5/PcI5WgJqhfZBom71NPAFdjIWdoX
I5gg2wNGD/k7Pd0GIz41hmkSTFq81TzD4/bqQc7MkjN2WYRnTdIlALdrLX4rYetd
gKyEdvblPKZzpF6Kj/YEvMs=
-----END PRIVATE KEY-----';

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

        $key = openssl_pkey_get_private(self::PRIVATE_KEY);

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

        $key = openssl_pkey_get_private(self::PRIVATE_KEY);

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
