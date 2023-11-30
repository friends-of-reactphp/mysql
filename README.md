# MySQL

[![CI status](https://github.com/friends-of-reactphp/mysql/actions/workflows/ci.yml/badge.svg)](https://github.com/friends-of-reactphp/mysql/actions)

Async MySQL database client for [ReactPHP](https://reactphp.org/).

> **Development version:** This branch contains the code for the upcoming
> version 0.7 release. For the code of the current stable version 0.6 release, check
> out the [`0.6.x` branch](https://github.com/friends-of-reactphp/mysql/tree/0.6.x).
>
> The upcoming version 0.7 release will be the way forward for this package.
> However, we will still actively support version 0.6 for those not yet on the
> latest version.
> See also [installation instructions](#install) for more details.

This is a MySQL database driver for [ReactPHP](https://reactphp.org/).
It implements the MySQL protocol and allows you to access your existing MySQL
database.
It is written in pure PHP and does not require any extensions.

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [MysqlClient](#mysqlclient)
    * [__construct()](#__construct)
    * [query()](#query)
    * [queryStream()](#querystream)
    * [ping()](#ping)
    * [quit()](#quit)
    * [close()](#close)
    * [error event](#error-event)
    * [close event](#close-event)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Quickstart example

This example runs a simple `SELECT` query and dumps all the records from a `book` table:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$mysql = new React\Mysql\MysqlClient('user:pass@localhost/bookstore');

$mysql->query('SELECT * FROM book')->then(
    function (React\Mysql\MysqlResult $command) {
        print_r($command->resultFields);
        print_r($command->resultRows);
        echo count($command->resultRows) . ' row(s) in set' . PHP_EOL;
    },
    function (Exception $error) {
        echo 'Error: ' . $error->getMessage() . PHP_EOL;
    }
);
```

See also the [examples](examples).

## Usage

### MysqlClient

The `MysqlClient` is responsible for exchanging messages with your MySQL server
and keeps track of pending queries.

```php
$mysql = new React\Mysql\MysqlClient($uri);

$mysql->query(…);
```

This class represents a connection that is responsible for communicating
with your MySQL server instance, managing the connection state and sending
your database queries. Internally, it creates the underlying database
connection only on demand once the first request is invoked on this
instance and will queue all outstanding requests until the underlying
connection is ready. This underlying connection will be reused for all
requests until it is closed. By default, idle connections will be held
open for 1ms (0.001s) when not used. The next request will either reuse
the existing connection or will automatically create a new underlying
connection if this idle time is expired.

From a consumer side this means that you can start sending queries to the
database right away while the underlying connection may still be
outstanding. Because creating this underlying connection may take some
time, it will enqueue all outstanding commands and will ensure that all
commands will be executed in correct order once the connection is ready.

If the underlying database connection fails, it will reject all
outstanding commands and will return to the initial "idle" state. This
means that you can keep sending additional commands at a later time which
will again try to open a new underlying connection. Note that this may
require special care if you're using transactions that are kept open for
longer than the idle period.

Note that creating the underlying connection will be deferred until the
first request is invoked. Accordingly, any eventual connection issues
will be detected once this instance is first used. You can use the
`quit()` method to ensure that the connection will be soft-closed
and no further commands can be enqueued. Similarly, calling `quit()` on
this instance when not currently connected will succeed immediately and
will not have to wait for an actual underlying connection.

#### __construct()

The `new MysqlClient(string $uri, ConnectorInterface $connector = null, LoopInterface $loop = null)` constructor can be used to
create a new `MysqlClient` instance.

The `$uri` parameter must contain the database host, optional
authentication, port and database to connect to:

```php
$mysql = new React\Mysql\MysqlClient('user:secret@localhost:3306/database');
```

Note that both the username and password must be URL-encoded (percent-encoded)
if they contain special characters:

```php
$user = 'he:llo';
$pass = 'p@ss';

$mysql = new React\Mysql\MysqlClient(
    rawurlencode($user) . ':' . rawurlencode($pass) . '@localhost:3306/db'
);
```

You can omit the port if you're connecting to default port `3306`:

```php
$mysql = new React\Mysql\MysqlClient('user:secret@localhost/database');
```

If you do not include authentication and/or database, then this method
will default to trying to connect as user `root` with an empty password
and no database selected. This may be useful when initially setting up a
database, but likely to yield an authentication error in a production system:

```php
$mysql = new React\Mysql\MysqlClient('localhost');
```

This method respects PHP's `default_socket_timeout` setting (default 60s)
as a timeout for establishing the underlying connection and waiting for
successful authentication. You can explicitly pass a custom timeout value
in seconds (or use a negative number to not apply a timeout) like this:

```php
$mysql = new React\Mysql\MysqlClient('localhost?timeout=0.5');
```

By default, idle connections will be held open for 1ms (0.001s) when not
used. The next request will either reuse the existing connection or will
automatically create a new underlying connection if this idle time is
expired. This ensures you always get a "fresh" connection and as such
should not be confused with a "keepalive" or "heartbeat" mechanism, as
this will not actively try to probe the connection. You can explicitly
pass a custom idle timeout value in seconds (or use a negative number to
not apply a timeout) like this:

```php
$mysql = new React\Mysql\MysqlClient('localhost?idle=10.0');
```

By default, the connection provides full UTF-8 support (using the
`utf8mb4` charset encoding). This should usually not be changed for most
applications nowadays, but for legacy reasons you can change this to use
a different ASCII-compatible charset encoding like this:

```php
$mysql = new React\Mysql\MysqlClient('localhost?charset=utf8mb4');
```

If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
proxy servers etc.), you can explicitly pass a custom instance of the
[`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):

```php
$connector = new React\Socket\Connector([
    'dns' => '127.0.0.1',
    'tcp' => [
        'bindto' => '192.168.10.1:0'
    ],
    'tls' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    )
]);

$mysql = new React\Mysql\MysqlClient('user:secret@localhost:3306/database', $connector);
```

This class takes an optional `LoopInterface|null $loop` parameter that can be used to
pass the event loop instance to use for this object. You can use a `null` value
here in order to use the [default loop](https://github.com/reactphp/event-loop#loop).
This value SHOULD NOT be given unless you're sure you want to explicitly use a
given event loop instance.

#### query()

The `query(string $query, array $params = []): PromiseInterface<MysqlResult>` method can be used to
perform an async query.

This method returns a promise that will resolve with a `MysqlResult` on
success or will reject with an `Exception` on error. The MySQL protocol
is inherently sequential, so that all queries will be performed in order
and outstanding queries will be put into a queue to be executed once the
previous queries are completed.

```php
$mysql->query('CREATE TABLE test ...');
$mysql->query('INSERT INTO test (id) VALUES (1)');
```

If this SQL statement returns a result set (such as from a `SELECT`
statement), this method will buffer everything in memory until the result
set is completed and will then resolve the resulting promise. This is
the preferred method if you know your result set to not exceed a few
dozens or hundreds of rows. If the size of your result set is either
unknown or known to be too large to fit into memory, you should use the
[`queryStream()`](#querystream) method instead.

```php
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
```

You can optionally pass an array of `$params` that will be bound to the
query like this:

```php
$mysql->query('SELECT * FROM user WHERE id > ?', [$id]);
```

The given `$sql` parameter MUST contain a single statement. Support
for multiple statements is disabled for security reasons because it
could allow for possible SQL injection attacks and this API is not
suited for exposing multiple possible results.

#### queryStream()

The `queryStream(string $sql, array $params = []): ReadableStreamInterface` method can be used to
perform an async query and stream the rows of the result set.

This method returns a readable stream that will emit each row of the
result set as a `data` event. It will only buffer data to complete a
single row in memory and will not store the whole result set. This allows
you to process result sets of unlimited size that would not otherwise fit
into memory. If you know your result set to not exceed a few dozens or
hundreds of rows, you may want to use the [`query()`](#query) method instead.

```php
$stream = $mysql->queryStream('SELECT * FROM user');
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
$stream = $mysql->queryStream('SELECT * FROM user WHERE id > ?', [$id]);
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
$mysql->queryStream('SELECT * FROM user')->pipe($formatter)->pipe($logger);
```

Note that as per the underlying stream definition, calling `pause()` and
`resume()` on this stream is advisory-only, i.e. the stream MAY continue
emitting some data until the underlying network buffer is drained. Also
notice that the server side limits how long a connection is allowed to be
in a state that has outgoing data. Special care should be taken to ensure
the stream is resumed in time. This implies that using `pipe()` with a
slow destination stream may cause the connection to abort after a while.

The given `$sql` parameter MUST contain a single statement. Support
for multiple statements is disabled for security reasons because it
could allow for possible SQL injection attacks and this API is not
suited for exposing multiple possible results.

#### ping()

The `ping(): PromiseInterface<void>` method can be used to
check that the connection is alive.

This method returns a promise that will resolve (with a void value) on
success or will reject with an `Exception` on error. The MySQL protocol
is inherently sequential, so that all commands will be performed in order
and outstanding command will be put into a queue to be executed once the
previous queries are completed.

```php
$mysql->ping()->then(function () {
    echo 'OK' . PHP_EOL;
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

#### quit()

The `quit(): PromiseInterface<void>` method can be used to
quit (soft-close) the connection.

This method returns a promise that will resolve (with a void value) on
success or will reject with an `Exception` on error. The MySQL protocol
is inherently sequential, so that all commands will be performed in order
and outstanding commands will be put into a queue to be executed once the
previous commands are completed.

```php
$mysql->query('CREATE TABLE test ...');
$mysql->quit();
```

This method will gracefully close the connection to the MySQL database
server once all outstanding commands are completed. See also
[`close()`](#close) if you want to force-close the connection without
waiting for any commands to complete instead.

#### close()

The `close(): void` method can be used to
force-close the connection.

Unlike the `quit()` method, this method will immediately force-close the
connection and reject all outstanding commands.

```php
$mysql->close();
```

Forcefully closing the connection will yield a warning in the server logs
and should generally only be used as a last resort. See also
[`quit()`](#quit) as a safe alternative.

#### error event

The `error` event will be emitted once a fatal error occurs, such as
when the connection is lost or is invalid.
The event receives a single `Exception` argument for the error instance.

```php
$mysql->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

This event will only be triggered for fatal errors and will be followed
by closing the connection. It is not to be confused with "soft" errors
caused by invalid SQL queries.

#### close event

The `close` event will be emitted once the connection closes (terminates).

```php
$mysql->on('close', function () {
    echo 'Connection closed' . PHP_EOL;
});
```

See also the [`close()`](#close) method.

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org/).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

Once released, this project will follow [SemVer](https://semver.org/).
At the moment, this will install the latest development version:

```bash
composer require react/mysql:^0.7@dev
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

This project aims to run on any platform and thus does not require any PHP
extensions and supports running on legacy PHP 5.4 through current PHP 8+ and
HHVM.
It's *highly recommended to use the latest supported PHP version* for this project.

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org/):

```bash
composer install
```

The test suite contains a number of functional integration tests that send
actual test SQL queries against your local database and thus rely on a local
MySQL test database with appropriate write access.
The test suite creates and modifies a test table in this database, so make sure
to not use a production database!
You can change your test database credentials by passing these ENV variables:

```bash
export DB_HOST=localhost
export DB_PORT=3306
export DB_USER=test
export DB_PASSWD=test
export DB_DBNAME=test
```

For example, to create an empty test database, you can also use a temporary
[`mysql` Docker image](https://hub.docker.com/_/mysql/) like this:

```bash
docker run -it --rm --net=host \
    -e MYSQL_RANDOM_ROOT_PASSWORD=yes -e MYSQL_DATABASE=test \
    -e MYSQL_USER=test -e MYSQL_PASSWORD=test mysql:5
```

To run the test suite, go to the project root and run:

```bash
vendor/bin/phpunit
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
