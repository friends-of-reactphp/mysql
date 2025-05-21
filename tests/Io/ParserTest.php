<?php

namespace React\Tests\Mysql\Io;

use React\Mysql\Commands\AuthenticateCommand;
use React\Mysql\Commands\QueryCommand;
use React\Mysql\Exception;
use React\Mysql\Io\Executor;
use React\Mysql\Io\Parser;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use React\Tests\Mysql\BaseTestCase;

class ParserTest extends BaseTestCase
{
    public function testClosingStreamEmitsErrorForCurrentCommand()
    {
        $stream = new ThroughStream();
        $executor = new Executor();

        $parser = new Parser($stream, $executor);
        $parser->start();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        // hack to inject command as current command
        $ref = new \ReflectionProperty($parser, 'currCommand');
        $ref->setAccessible(true);
        $ref->setValue($parser, $command);

        $stream->close();

        $this->assertInstanceOf('RuntimeException', $error);
        assert($error instanceof \RuntimeException);

        $this->assertEquals('Connection closing (ECONNABORTED)', $error->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $error->getCode());
    }

    public function testParseValidAuthPluginWillSendAuthResponse()
    {
        $stream = new ThroughStream();

        $outgoing = new ThroughStream();
        $outgoing->on('data', $this->expectCallableOnceWith("\x08\0\0\x01" . "response"));

        $command = $this->getMockBuilder('React\Mysql\Commands\AuthenticateCommand')->disableOriginalConstructor()->getMock();
        $command->expects($this->once())->method('authenticatePacket')->with($this->anything(), 'caching_sha2_password')->willReturn('response');

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser(new CompositeStream($stream, $outgoing), $executor);
        $parser->start();

        $stream->write("\x49\0\0\0\x0a\x38\x2e\x34\x2e\x35\0\x5e\0\0\0\x08\x0c\x41\x44\x12\x5e\x69\x59\0\xff\xff\xff\x02\0\xff\xdf\x15\0\0\0\0\0\0\0\0\0\0\x3c\x2c\x5e\x54\x06\x04\x01\x61\x01\x20\x79\x1b\0\x63\x61\x63\x68\x69\x6e\x67\x5f\x73\x68\x61\x32\x5f\x70\x61\x73\x73\x77\x6f\x72\x64\0");

        $ref = new \ReflectionProperty($parser, 'authPlugin');
        $ref->setAccessible(true);
        $this->assertEquals('caching_sha2_password', $ref->getValue($parser));
    }

    public function testUnexpectedAuthPluginShouldEmitErrorOnAuthenticateCommandAndCloseStream()
    {
        $stream = new ThroughStream();
        $stream->on('close', $this->expectCallableOnce());

        $command = new AuthenticateCommand('root', '', 'test', 'utf8mb4');
        $command->on('error', $this->expectCallableOnceWith(new \UnexpectedValueException('Unknown authentication plugin "sha256_password" requested by server')));

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser($stream, $executor);
        $parser->start();

        $stream->write("\x43\0\0\0\x0a\x38\x2e\x34\x2e\x35\0\x5e\0\0\0\x08\x0c\x41\x44\x12\x5e\x69\x59\0\xff\xff\xff\x02\0\xff\xdf\x15\0\0\0\0\0\0\0\0\0\0\x3c\x2c\x5e\x54\x06\x04\x01\x61\x01\x20\x79\x1b\0\x73\x68\x61\x32\x35\x36\x5f\x70\x61\x73\x73\x77\x6f\x72\x64\0");
    }

    public function testParseAuthMoreDataWithFastAuthSuccessWillPrintDebugLogAndWaitForOkPacketWithoutSendingPacket()
    {
        $stream = new ThroughStream();
        $stream->on('close', $this->expectCallableNever());

        $outgoing = new ThroughStream();
        $outgoing->on('data', $this->expectCallableNever());

        $executor = new Executor();

        $parser = new Parser(new CompositeStream($stream, $outgoing), $executor);
        $parser->start();

        $ref = new \ReflectionProperty($parser, 'debug');
        $ref->setAccessible(true);
        $ref->setValue($parser, true);

        $ref = new \ReflectionProperty($parser, 'phase');
        $ref->setAccessible(true);
        $ref->setValue($parser, Parser::PHASE_AUTH_SENT);

        $ref = new \ReflectionProperty($parser, 'authPlugin');
        $ref->setAccessible(true);
        $ref->setValue($parser, 'caching_sha2_password');

        $this->expectOutputRegex('/Fast auth success\n$/');
        $stream->write("\x02\0\0\0" . "\x01\x03");
    }

    public function testParseAuthMoreDataWithFastAuthFailureWillSendCertificateRequest()
    {
        $stream = new ThroughStream();
        $stream->on('close', $this->expectCallableNever());

        $outgoing = new ThroughStream();
        $outgoing->on('data', $this->expectCallableOnceWith("\x01\0\0\x01" . "\x02"));

        $executor = new Executor();

        $parser = new Parser(new CompositeStream($stream, $outgoing), $executor);
        $parser->start();

        $ref = new \ReflectionProperty($parser, 'phase');
        $ref->setAccessible(true);
        $ref->setValue($parser, Parser::PHASE_AUTH_SENT);

        $ref = new \ReflectionProperty($parser, 'authPlugin');
        $ref->setAccessible(true);
        $ref->setValue($parser, 'caching_sha2_password');

        $stream->write("\x02\0\0\0" . "\x01\x04");
    }

    public function testParseAuthMoreDataWithCertificateWillSendEncryptedPassword()
    {
        $stream = new ThroughStream();
        $stream->on('close', $this->expectCallableNever());

        $outgoing = new ThroughStream();
        $outgoing->on('data', $this->expectCallableOnceWith("\x09\0\0\x01" . "encrypted"));

        $command = $this->getMockBuilder('React\Mysql\Commands\AuthenticateCommand')->disableOriginalConstructor()->getMock();
        $command->expects($this->once())->method('authSha256')->with('', '---')->willReturn('encrypted');

        $executor = new Executor();

        $parser = new Parser(new CompositeStream($stream, $outgoing), $executor);
        $parser->start();

        $ref = new \ReflectionProperty($parser, 'phase');
        $ref->setAccessible(true);
        $ref->setValue($parser, Parser::PHASE_AUTH_SENT);

        $ref = new \ReflectionProperty($parser, 'authPlugin');
        $ref->setAccessible(true);
        $ref->setValue($parser, 'caching_sha2_password');

        $ref = new \ReflectionProperty($parser, 'currCommand');
        $ref->setAccessible(true);
        $ref->setValue($parser, $command);

        $stream->write("\x04\0\0\0" . "\x01---");
    }

    public function testAuthMoreDataWithCertificateWillEmitErrorAndCloseConnectionWhenEncryptingPasswordThrows()
    {
        $stream = new ThroughStream();
        $stream->on('close', $this->expectCallableOnce());

        $outgoing = new ThroughStream();
        $outgoing->on('data', $this->expectCallableNever());

        $command = $this->getMockBuilder('React\Mysql\Commands\AuthenticateCommand')->disableOriginalConstructor()->getMock();
        $command->expects($this->once())->method('authSha256')->with('', '---')->willThrowException(new \UnexpectedValueException('Error'));
        $command->expects($this->once())->method('emit')->with('error', [new \UnexpectedValueException('Error')]);

        $executor = new Executor();

        $parser = new Parser(new CompositeStream($stream, $outgoing), $executor);
        $parser->start();

        $ref = new \ReflectionProperty($parser, 'phase');
        $ref->setAccessible(true);
        $ref->setValue($parser, Parser::PHASE_AUTH_SENT);

        $ref = new \ReflectionProperty($parser, 'authPlugin');
        $ref->setAccessible(true);
        $ref->setValue($parser, 'caching_sha2_password');

        $ref = new \ReflectionProperty($parser, 'currCommand');
        $ref->setAccessible(true);
        $ref->setValue($parser, $command);

        $stream->write("\x04\0\0\0" . "\x01---");
    }

    public function testUnexpectedErrorWithoutCurrentCommandWillBeIgnored()
    {
        $stream = new ThroughStream();

        $executor = new Executor();

        $parser = new Parser($stream, $executor);
        $parser->start();

        $stream->on('close', $this->expectCallableNever());

        $stream->write("\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44));
        $stream->write("\x17\0\0\0" . "\xFF" . "\x10\x04" . "Too many connections");
    }

    public function testReceivingErrorFrameDuringHandshakeShouldEmitErrorOnFollowingCommand()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser($stream, $executor);
        $parser->start();

        $stream->write("\x17\0\0\0" . "\xFF" . "\x10\x04" . "Too many connections");

        $this->assertTrue($error instanceof Exception);
        $this->assertEquals(1040, $error->getCode());
        $this->assertEquals('Too many connections', $error->getMessage());
    }

    public function testReceivingErrorFrameForQueryShouldEmitError()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser($stream, $executor);
        $parser->start();

        $stream->on('close', $this->expectCallableNever());

        $stream->write("\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44));
        $stream->write("\x1E\0\0\1" . "\xFF" . "\x46\x04" . "#abcde" . "Unknown thread id: 42");

        $this->assertTrue($error instanceof Exception);
        $this->assertEquals(1094, $error->getCode());
        $this->assertEquals('Unknown thread id: 42', $error->getMessage());
    }

    public function testReceivingErrorFrameForQueryAfterResultSetHeadersShouldEmitError()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser(new CompositeStream($stream, new ThroughStream()), $executor);
        $parser->start();

        $stream->on('close', $this->expectCallableNever());

        $stream->write("\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44));
        $stream->write("\x01\0\0\1" . "\x01");
        $stream->write("\x1F\0\0\2" . "\x03" . "def" . "\0" . "\0" . "\0" . "\x09" . "sleep(10)" . "\0" . "\xC0" . "\x3F\0" . "\1\0\0\0" . "\3" . "\x81\0". "\0" . "\0\0");
        $stream->write("\x05\0\0\3" . "\xFE" . "\0\0\2\0");
        $stream->write("\x28\0\0\4" . "\xFF" . "\x25\x05" . "#abcde" . "Query execution was interrupted");

        $this->assertTrue($error instanceof Exception);
        $this->assertEquals(1317, $error->getCode());
        $this->assertEquals('Query execution was interrupted', $error->getMessage());

        $ref = new \ReflectionProperty($parser, 'rsState');
        $ref->setAccessible(true);
        $this->assertEquals(0, $ref->getValue($parser));

        $ref = new \ReflectionProperty($parser, 'resultFields');
        $ref->setAccessible(true);
        $this->assertEquals([], $ref->getValue($parser));
    }

    public function testReceivingInvalidPacketWithMissingDataShouldEmitErrorAndCloseConnection()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser(new CompositeStream($stream, new ThroughStream()), $executor);
        $parser->start();

        // hack to inject command as current command
        $ref = new \ReflectionProperty($parser, 'currCommand');
        $ref->setAccessible(true);
        $ref->setValue($parser, $command);

        $stream->on('close', $this->expectCallableOnce());

        $stream->write("\x32\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 43));

        $this->assertTrue($error instanceof \UnexpectedValueException);
        $this->assertEquals('Unexpected protocol error, received malformed packet: Not enough data in buffer', $error->getMessage());
        $this->assertEquals(0, $error->getCode());
        $this->assertInstanceOf('UnderflowException', $error->getPrevious());
    }

    public function testReceivingInvalidPacketWithExcessiveDataShouldEmitErrorAndCloseConnection()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser(new CompositeStream($stream, new ThroughStream()), $executor);
        $parser->start();

        // hack to inject command as current command
        $ref = new \ReflectionProperty($parser, 'currCommand');
        $ref->setAccessible(true);
        $ref->setValue($parser, $command);

        $stream->on('close', $this->expectCallableOnce());

        $stream->write("\x34\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 45));

        $this->assertTrue($error instanceof \UnexpectedValueException);
        $this->assertEquals('Unexpected protocol error, received malformed packet with 1 unknown byte(s)', $error->getMessage());
        $this->assertEquals(0, $error->getCode());
        $this->assertNull($error->getPrevious());
    }

    public function testReceivingIncompleteErrorFrameDuringHandshakeShouldNotEmitError()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableNever());

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser($stream, $executor);
        $parser->start();

        $stream->write("\xFF\0\0\0" . "\xFF" . "\x12\x34" . "Some incomplete error message...");
    }
}
