<?php

use React\MySQL\ConnectionInterface;
use React\MySQL\QueryResult;
use React\MySQL\Factory;
use React\Stream\ReadableResourceStream;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$uri = 'test:test@localhost/test';

// open a STDIN stream to read keyboard input (not supported on Windows)
$stdin = new ReadableResourceStream(STDIN, $loop);
$stdin->pause();

//create a mysql connection for executing queries
$factory->createConnection($uri)->then(function (ConnectionInterface $connection) use ($stdin) {
    echo 'Connection success.' . PHP_EOL;
    $stdin->resume();

    $stdin->on('data', function ($line) use ($connection) {
        $query = trim($line);

        if ($query === '') {
            // skip empty commands
            return;
        }
        if ($query === 'exit') {
            // exit command should close the connection
            echo 'bye.' . PHP_EOL;
            $connection->quit();
            return;
        }

        $time = microtime(true);
        $connection->query($query)->then(function (QueryResult $command) use ($time) {
            if (isset($command->resultRows)) {
                // this is a response to a SELECT etc. with some rows (0+)
                echo implode("\t", array_column($command->resultFields, 'name')) . PHP_EOL;
                foreach ($command->resultRows as $row) {
                    echo implode("\t", $row) . PHP_EOL;
                }

                printf(
                    '%d row%s in set (%.03f sec)%s',
                    count($command->resultRows),
                    count($command->resultRows) === 1 ? '' : 's',
                    microtime(true) - $time,
                    PHP_EOL
                );
            } else {
                // this is an OK message in response to an UPDATE etc.
                // the insertId will only be set if this is
                if ($command->insertId !== 0) {
                    var_dump('last insert ID', $command->insertId);
                }

                printf(
                    'Query OK, %d row%s affected (%.03f sec)%s',
                    $command->affectedRows,
                    $command->affectedRows === 1 ? '' : 's',
                    microtime(true) - $time,
                    PHP_EOL
                );
            }
        }, function (Exception $error) {
            // the query was not executed successfully
            echo 'Error: ' . $error->getMessage() . PHP_EOL;
        });
    });

    // close connection when STDIN closes (EOF or CTRL+D)
    $stdin->on('close', function () use ($connection) {
        $connection->quit();
    });

    // close STDIN (stop reading) when connection closes
    $connection->on('close', function () use ($stdin) {
        $stdin->close();
        echo 'Disconnected.' . PHP_EOL;
    });
}, function (Exception $e) use ($stdin) {
    echo 'Connection error: ' . $e->getMessage() . PHP_EOL;
    $stdin->close();
});

$loop->run();
