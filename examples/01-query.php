<?php

use React\MySQL\Commands\QueryCommand;

require __DIR__ . '/../vendor/autoload.php';

//create the main loop
$loop = React\EventLoop\Factory::create();

//create a mysql connection for executing queries
$connection = new React\MySQL\Connection($loop, array(
    'dbname' => 'test',
    'user'   => 'test',
    'passwd' => 'test',
));

$connection->connect(function () {});

$query = isset($argv[1]) ? $argv[1] : 'select * from book';
$connection->query($query, function (QueryCommand $command) {
    if ($command->hasError()) {
        // test whether the query was executed successfully
        // get the error object, instance of Exception.
        $error = $command->getError();
        echo 'Error: ' . $error->getMessage() . PHP_EOL;
    } elseif (isset($command->resultRows)) {
        // this is a response to a SELECT etc. with some rows (0+)
        print_r($command->resultFields);
        print_r($command->resultRows);
        echo count($command->resultRows) . ' row(s) in set' . PHP_EOL;
    } else {
        // this is an OK message in response to an UPDATE etc.
        if ($command->insertId !== 0) {
            var_dump('last insert ID', $command->insertId);
        }
        echo 'Query OK, ' . $command->affectedRows . ' row(s) affected' . PHP_EOL;
    }
});

$connection->close();

$loop->run();
