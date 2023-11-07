<?php

// $ php examples/01-query.php
// $ MYSQL_URI=test:test@localhost/test php examples/01-query.php "SELECT * FROM book"

use React\MySQL\Factory;
use React\MySQL\QueryResult;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Factory();
$connection = $factory->createLazyConnection(getenv('MYSQL_URI') ?: 'test:test@localhost/test');

$query = isset($argv[1]) ? $argv[1] : 'select * from book';
$connection->query($query)->then(function (QueryResult $command) {
    if (isset($command->resultRows)) {
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
}, function (Exception $error) {
    // the query was not executed successfully
    echo 'Error: ' . $error->getMessage() . PHP_EOL;
});
