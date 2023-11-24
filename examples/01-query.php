<?php

// $ php examples/01-query.php
// $ MYSQL_URI=test:test@localhost/test php examples/01-query.php "SELECT * FROM book"

require __DIR__ . '/../vendor/autoload.php';

$mysql = new React\Mysql\MysqlClient(getenv('MYSQL_URI') ?: 'test:test@localhost/test');

$query = isset($argv[1]) ? $argv[1] : 'select * from book';
$mysql->query($query)->then(function (React\Mysql\MysqlResult $command) {
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
