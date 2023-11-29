<?php

// $ php examples/11-interactive.php
// $ MYSQL_URI=test:test@localhost/test php examples/11-interactive.php

require __DIR__ . '/../vendor/autoload.php';

$mysql = new React\Mysql\MysqlClient(getenv('MYSQL_URI') ?: 'test:test@localhost/test');

// open a STDIN stream to read keyboard input (not supported on Windows)
$stdin = new React\Stream\ReadableResourceStream(STDIN);

$stdin->on('data', function ($line) use ($mysql) {
    $query = trim($line);

    if ($query === '') {
        // skip empty commands
        return;
    }
    if ($query === 'exit') {
        // exit command should close the connection
        echo 'bye.' . PHP_EOL;
        $mysql->quit();
        return;
    }

    $time = microtime(true);
    $mysql->query($query)->then(function (React\Mysql\MysqlResult $command) use ($time) {
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
$stdin->on('close', function () use ($mysql) {
    $mysql->quit();
});

// close STDIN (stop reading) when connection closes
$mysql->on('close', function () use ($stdin) {
    $stdin->close();
    echo 'Disconnected.' . PHP_EOL;
});

echo '# Entering interactive mode ready, hit CTRL-D to quit' . PHP_EOL;
