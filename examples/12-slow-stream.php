<?php

// $ php examples/12-slow-stream.php "SHOW VARIABLES"

use React\MySQL\ConnectionInterface;
use React\MySQL\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$uri = 'test:test@localhost/test';
$query = isset($argv[1]) ? $argv[1] : 'select * from book';

//create a mysql connection for executing query
$factory->createConnection($uri)->then(function (ConnectionInterface $connection) use ($query, $loop) {
    // The protocol parser reads rather large chunked from the underlying connection
    // and as such can yield multiple (dozens to hundreds) rows from a single data
    // chunk. We try to artifically limit the stream chunk size here to try to
    // only ever read a single row so we can demonstrate throttling this stream.
    // It goes without saying this is only a hack! Real world applications rarely
    // have the need to limit the chunk size. As an alternative, consider using
    // a stream decorator that rate-limits and buffers the resulting flow.
    try {
        // accept private "stream" (instanceof React\Socket\ConnectionInterface)
        $ref = new ReflectionProperty($connection, 'stream');
        $ref->setAccessible(true);
        $conn = $ref->getValue($connection);

        // access private "input" (instanceof React\Stream\DuplexStreamInterface)
        $ref = new ReflectionProperty($conn, 'input');
        $ref->setAccessible(true);
        $stream = $ref->getValue($conn);

        // reduce private bufferSize to just a few bytes to slow things down
        $ref = new ReflectionProperty($stream, 'bufferSize');
        $ref->setAccessible(true);
        $ref->setValue($stream, 8);
    } catch (Exception $e) {
        echo 'Warning: Unable to reduce buffer size: ' . $e->getMessage() . PHP_EOL;
    }

    $stream = $connection->queryStream($query);

    $throttle = null;
    $stream->on('data', function ($row) use ($loop, &$throttle, $stream) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // simple throttle mechanism: explicitly pause the result stream and
        // resume it again after some time.
        if ($throttle === null) {
            $throttle = $loop->addTimer(1.0, function () use ($stream, &$throttle) {
                $throttle = null;
                $stream->resume();
            });
            $stream->pause();
        }
    });

    $stream->on('error', function (Exception $e) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
    });

    $stream->on('close', function () use ($loop, &$throttle) {
        echo 'CLOSED' . PHP_EOL;

        if ($throttle) {
            $loop->cancelTimer($throttle);
            $throttle = null;
        }
    });

    $connection->quit();
}, 'printf');

$loop->run();
