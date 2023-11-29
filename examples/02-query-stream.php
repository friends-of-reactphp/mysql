<?php

// $ php examples/02-query-stream.php "SHOW VARIABLES"
// $ MYSQL_URI=test:test@localhost/test php examples/02-query-stream.php "SELECT * FROM book"

require __DIR__ . '/../vendor/autoload.php';

$mysql = new React\Mysql\MysqlClient(getenv('MYSQL_URI') ?: 'test:test@localhost/test');

$query = isset($argv[1]) ? $argv[1] : 'select * from book';
$stream = $mysql->queryStream($query);

$stream->on('data', function ($row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
});

$stream->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

$stream->on('close', function () {
    echo 'CLOSED' . PHP_EOL;
});
