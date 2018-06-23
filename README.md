# MySQL

[![Build Status](https://travis-ci.org/friends-of-reactphp/mysql.svg?branch=master)](https://travis-ci.org/friends-of-reactphp/mysql)

Async MySQL database client for [ReactPHP](https://reactphp.org/).

This is a MySQL database driver for [ReactPHP](https://reactphp.org/).
It implements the MySQL protocol and allows you to access your existing MySQL
database.
It is written in pure PHP and does not require any extensions.

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [Connection](#connection)
    * [connect()](#connect)
    * [query()](#query)
    * [queryStream()](#querystream)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Quickstart example

This example runs a simple `SELECT` query and dumps all the records from a `book` table:

```php
$loop = React\EventLoop\Factory::create();

$connection = new React\MySQL\Connection($loop, array(
    'dbname' => 'test',
    'user'   => 'test',
    'passwd' => 'test',
));

$connection->connect(function () {});

$connection->query('SELECT * FROM book')->then(
    function (QueryResult $command) {
        print_r($command->resultFields);
        print_r($command->resultRows);
        echo count($command->resultRows) . ' row(s) in set' . PHP_EOL;
    },
    function (Exception $error) {
        echo 'Error: ' . $error->getMessage() . PHP_EOL;
    }
);

$connection->close();

$loop->run();
```

See also the [examples](examples).

## Usage

### Connection

The `Connection` is responsible for communicating with your MySQL server
instance, managing the connection state and sending your database queries.
It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage).

```php
$loop = React\EventLoop\Factory::create();

$options = array(
    'host'   => '127.0.0.1',
    'port'   => 3306,
    'user'   => 'root',
    'passwd' => '',
    'dbname' => '',
);

$connection = new Connection($loop, $options);
```

If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
proxy servers etc.), you can explicitly pass a custom instance of the
[`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):

```php
$connector = new \React\Socket\Connector($loop, array(
    'dns' => '127.0.0.1',
    'tcp' => array(
        'bindto' => '192.168.10.1:0'
    ),
    'tls' => array(
        'verify_peer' => false,
        'verify_peer_name' => false
    )
));

$connection = new Connection($loop, $options, $connector);
```

#### connect()

The `connect(callable $callback): void` method can be used to
connect to the MySQL server.

It accepts a `callable $callback` parameter which is the handler that will
be called when the connection succeeds or fails.

```php
$connection->connect(function (?Exception $error, $connection) {
    if ($error) {
        echo 'Connection failed: ' . $error->getMessage();
    } else {
        echo 'Successfully connected';
    }
});
```

This method should be invoked once after the `Connection` is initialized.
You can queue additional `query()`, `ping()` and `close()` calls after
invoking this method without having to await its resolution first.

This method throws an `Exception` if the connection is already initialized,
i.e. it MUST NOT be called more than once.

#### query()

The `query(string $query, array $params = array()): PromiseInterface` method can be used to
perform an async query.

This method returns a promise that will resolve with a `QueryResult` on
success or will reject with an `Exception` on error. The MySQL protocol
is inherently sequential, so that all queries will be performed in order
and outstanding queries will be put into a queue to be executed once the
previous queries are completed.

```php
$connection->query('CREATE TABLE test ...');
$connection->query('INSERT INTO test (id) VALUES (1)');
```

If this SQL statement returns a result set (such as from a `SELECT`
statement), this method will buffer everything in memory until the result
set is completed and will then resolve the resulting promise. This is
the preferred method if you know your result set to not exceed a few
dozens or hundreds of rows. If the size of your result set is either
unknown or known to be too large to fit into memory, you should use the
[`queryStream()`](#querystream) method instead.

```php
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
```

You can optionally pass an array of `$params` that will be bound to the
query like this:

```php
$connection->query('SELECT * FROM user WHERE id > ?', [$id]);
```

The given `$sql` parameter MUST contain a single statement. Support
for multiple statements is disabled for security reasons because it
could allow for possible SQL injection attacks and this API is not
suited for exposing multiple possible results.

#### queryStream()

The `queryStream(string $sql, array $params = array()): ReadableStreamInterface` method can be used to
perform an async query and stream the rows of the result set.

This method returns a readable stream that will emit each row of the
result set as a `data` event. It will only buffer data to complete a
single row in memory and will not store the whole result set. This allows
you to process result sets of unlimited size that would not otherwise fit
into memory. If you know your result set to not exceed a few dozens or
hundreds of rows, you may want to use the [`query()`](#query) method instead.

```php
$stream = $connection->queryStream('SELECT * FROM user');
$stream->on('data', function ($row) {
    echo $row['name'] . PHP_EOL;
});
$stream->on('end', function () {
    echo 'Completed.';
});
```

You can optionally pass an array of `$params` that will be bound to the
query like this:

```php
$stream = $connection->queryStream('SELECT * FROM user WHERE id > ?', [$id]);
```

This method is specifically designed for queries that return a result set
(such as from a `SELECT` or `EXPLAIN` statement). Queries that do not
return a result set (such as a `UPDATE` or `INSERT` statement) will not
emit any `data` events.

See also [`ReadableStreamInterface`](https://github.com/reactphp/stream#readablestreaminterface)
for more details about how readable streams can be used in ReactPHP. For
example, you can also use its `pipe()` method to forward the result set
rows to a [`WritableStreamInterface`](https://github.com/reactphp/stream#writablestreaminterface)
like this:

```php
$connection->queryStream('SELECT * FROM user')->pipe($formatter)->pipe($logger);
```

The given `$sql` parameter MUST contain a single statement. Support
for multiple statements is disabled for security reasons because it
could allow for possible SQL injection attacks and this API is not
suited for exposing multiple possible results.

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require react/mysql:^0.3.3
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

This project aims to run on any platform and thus does not require any PHP
extensions and supports running on legacy PHP 5.4 through current PHP 7+ and
HHVM.
It's *highly recommended to use PHP 7+* for this project.

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org):

```bash
$ composer install
```

The test suite contains a number of functional integration tests that send
actual test SQL queries against your local database and thus rely on a local
MySQL test database with appropriate write access.
The test suite creates and modifies a test table in this database, so make sure
to not use a production database!
You can change your test database credentials by passing these ENV variables:

```bash
$ export DB_HOST=localhost
$ export DB_PORT=3306
$ export DB_USER=test
$ export DB_PASSWD=test
$ export DB_DBNAME=test
```

For example, to create an empty test database, you can also use a temporary
[`mysql` Docker image](https://hub.docker.com/_/mysql/) like this:

```bash
$ docker run -it --rm --net=host \
    -e MYSQL_RANDOM_ROOT_PASSWORD=yes -e MYSQL_DATABASE=test \
    -e MYSQL_USER=test -e MYSQL_PASSWORD=test mysql
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

## License

MIT, see [LICENSE file](LICENSE).

This is a community project now managed by
[@friends-of-reactphp](https://github.com/friends-of-reactphp).
The original implementation was created by
[@bixuehujin](https://github.com/bixuehujin) starting in 2013 and has been
migrated to [@friends-of-reactphp](https://github.com/friends-of-reactphp) in
2018 to help with maintenance and upcoming feature development.

The original implementation was made possible thanks to the following projects:

* [phpdaemon](https://github.com/kakserpom/phpdaemon): the MySQL protocol
  implementation is based on code of this project (with permission).
* [node-mysql](https://github.com/felixge/node-mysql): the API design is
  inspired by this project.
