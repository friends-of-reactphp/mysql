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

    public function testAuthenticatePacketWithSecretPassword()
    {
        $command = new AuthenticateCommand('root', 'secret', 'test', 'utf8mb4');

        $data = $command->authenticatePacket('scramble', null, new Buffer());

        $this->assertEquals("\x8d\xa6\0\0\0\0\0\x01\x2d" . str_repeat("\0", 23) . "root\0" . "\x14\x18\xd8\x8d\x74\x77\x2c\x27\x22\x89\xe1\xcd\xbc\x4b\x5f\x77\x08\x18\x3c\x6e\xba" . "test\0", $data);
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
}
