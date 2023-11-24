<?php

// $ php examples/12-slow-stream.php "SHOW VARIABLES"
// $ MYSQL_URI=test:test@localhost/test php examples/12-slow-stream.php "SELECT * FROM book"

use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

$mysql = new React\Mysql\MysqlClient(getenv('MYSQL_URI') ?: 'test:test@localhost/test');

$query = isset($argv[1]) ? $argv[1] : 'select * from book';
$stream = $mysql->queryStream($query);

$ref = new ReflectionProperty($mysql, 'connecting');
$ref->setAccessible(true);
$promise = $ref->getValue($mysql);
assert($promise instanceof React\Promise\PromiseInterface);

$promise->then(function (React\Mysql\Io\Connection $connection) {
    // The protocol parser reads rather large chunks from the underlying connection
    // and as such can yield multiple (dozens to hundreds) rows from a single data
    // chunk. We try to artificially limit the stream chunk size here to try to
    // only ever read a single row so we can demonstrate throttling this stream.
    // It goes without saying this is only a hack! Real world applications rarely
    // have the need to limit the chunk size. As an alternative, consider using
    // a stream decorator that rate-limits and buffers the resulting flow.
    try {
        // accept private "stream" (instanceof React\Socket\ConnectionInterface)
        $ref = new ReflectionProperty($connection, 'stream');
        $ref->setAccessible(true);
        $conn = $ref->getValue($connection);
        assert($conn instanceof React\Socket\ConnectionInterface);

        // access private "input" (instanceof React\Stream\DuplexStreamInterface)
        $ref = new ReflectionProperty($conn, 'input');
        $ref->setAccessible(true);
        $stream = $ref->getValue($conn);
        assert($stream instanceof React\Stream\DuplexStreamInterface);

        // reduce private bufferSize to just a few bytes to slow things down
        $ref = new ReflectionProperty($stream, 'bufferSize');
        $ref->setAccessible(true);
        $ref->setValue($stream, 8);
    } catch (Exception $e) {
        echo 'Warning: Unable to reduce buffer size: ' . $e->getMessage() . PHP_EOL;
    }
});

$throttle = null;
$stream->on('data', function ($row) use (&$throttle, $stream) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    // simple throttle mechanism: explicitly pause the result stream and
    // resume it again after some time.
    if ($throttle === null) {
        $throttle = Loop::addTimer(1.0, function () use ($stream, &$throttle) {
            $throttle = null;
            $stream->resume();
        });
        $stream->pause();
    }
});

$stream->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

$stream->on('close', function () use (&$throttle) {
    echo 'CLOSED' . PHP_EOL;

    if ($throttle) {
        Loop::cancelTimer($throttle);
        $throttle = null;
    }
});

$mysql->quit();
