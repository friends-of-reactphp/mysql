<?php

use React\MySQL\Commands\QueryCommand;
use React\Stream\ReadableResourceStream;
use React\MySQL\ConnectionInterface;

require __DIR__ . '/../vendor/autoload.php';

//create the main loop
$loop = React\EventLoop\Factory::create();

// open a STDIN stream to read keyboard input (not supported on Windows)
$stdin = new ReadableResourceStream(STDIN, $loop);

//create a mysql connection for executing queries
$connection = new React\MySQL\Connection($loop, array(
    'dbname' => 'test',
    'user'   => 'test',
    'passwd' => 'test',
));

$connection->connect(function ($e) use ($stdin) {
    if ($e === null) {
        echo 'Connection success.' . PHP_EOL;
    } else {
        echo 'Connection error: ' . $e->getMessage() . PHP_EOL;
        $stdin->close();
    }
});

$stdin->on('data', function ($line) use ($connection) {
    $query = trim($line);

    if ($query === '') {
        // skip empty commands
        return;
    }
    if ($query === 'exit') {
        // exit command should close the connection
        echo 'bye.' . PHP_EOL;
        $connection->close();
        return;
    }

    $time = microtime(true);
    $connection->query($query, function (QueryCommand $command) use ($time) {
        if ($command->hasError()) {
            // test whether the query was executed successfully
            // get the error object, instance of Exception.
            $error = $command->getError();
            echo 'Error: ' . $error->getMessage() . PHP_EOL;
        } elseif (isset($command->resultRows)) {
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
    });
});

// close connection when STDIN closes (EOF or CTRL+D)
$stdin->on('close', function () use ($connection) {
    if ($connection->getState() === ConnectionInterface::STATE_AUTHENTICATED) {
        $connection->close();
    }
});

// close STDIN (stop reading) when connection closes
$connection->on('close', function () use ($stdin) {
    $stdin->close();
    echo 'Disconnected.' . PHP_EOL;
});

$loop->run();
