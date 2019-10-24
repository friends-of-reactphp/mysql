
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
  * [Factory](#factory)
    * [createConnection()](#createconnection)
    * [createLazyConnection()](#createlazyconnection)
  * [ConnectionInterface](#connectioninterface)
    * [query()](#query)
    * [queryStream()](#querystream)
    * [ping()](#ping)
    * [quit()](#quit)
    * [close()](#close)
    * [Events](#events)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Quickstart example

This example runs a simple `SELECT` query and dumps all the records from a `book` table:

```php
$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$uri = 'test:test@localhost/test';
$connection = $factory->createLazyConnection($uri);

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

$connection->quit();

$loop->run();
```

See also the [examples](examples).

## Usage

### Factory

The `Factory` is responsible for creating your [`ConnectionInterface`](#connectioninterface) instance.
It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage).

```php
$loop = \React\EventLoop\Factory::create();
$factory = new Factory($loop);
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

$factory = new Factory($loop, $connector);
```

#### createConnection()

The `createConnection(string $url): PromiseInterface<ConnectionInterface, Exception>` method can be used to
create a new [`ConnectionInterface`](#connectioninterface).

It helps with establishing a TCP/IP connection to your MySQL database
and issuing the initial authentication handshake.

```php
$factory->createConnection($url)->then(
    function (ConnectionInterface $connection) {
        // client connection established (and authenticated)
    },
    function (Exception $e) {
        // an error occured while trying to connect or authorize client
    }
);
```

The method returns a [Promise](https://github.com/reactphp/promise) that
will resolve with a [`ConnectionInterface`](#connectioninterface)
instance on success or will reject with an `Exception` if the URL is
invalid or the connection or authentication fails.

The returned Promise is implemented in such a way that it can be
cancelled when it is still pending. Cancelling a pending promise will
reject its value with an Exception and will cancel the underlying TCP/IP
connection attempt and/or MySQL authentication.

```php
$promise = $factory->createConnection($url);

$loop->addTimer(3.0, function () use ($promise) {
    $promise->cancel();
});
```

The `$url` parameter must contain the database host, optional
authentication, port and database to connect to:

```php
$factory->createConnection('user:secret@localhost:3306/database');
```

Note that both the username and password must be URL-encoded (percent-encoded)
if they contain special characters:

```php
$user = 'he:llo';
$pass = 'p@ss';

$promise = $factory->createConnection(
    rawurlencode($user) . ':' . rawurlencode($pass) . '@localhost:3306/db'
);
```

You can omit the port if you're connecting to default port `3306`:

```php
$factory->createConnection('user:secret@localhost/database');
```

If you do not include authentication and/or database, then this method
will default to trying to connect as user `root` with an empty password
and no database selected. This may be useful when initially setting up a
database, but likely to yield an authentication error in a production system:

```php
$factory->createConnection('localhost');
```

This method respects PHP's `default_socket_timeout` setting (default 60s)
as a timeout for establishing the connection and waiting for successful
authentication. You can explicitly pass a custom timeout value in seconds
(or use a negative number to not apply a timeout) like this:

```php
$factory->createConnection('localhost?timeout=0.5');
```

#### createLazyConnection()

Creates a new connection.

It helps with establishing a TCP/IP connection to your MySQL database
and issuing the initial authentication handshake.

```php
$connection = $factory->createLazyConnection($url);

$connection->query(…);
```

This method immediately returns a "virtual" connection implementing the
[`ConnectionInterface`](#connectioninterface) that can be used to
interface with your MySQL database. Internally, it lazily creates the
underlying database connection only on demand once the first request is
invoked on this instance and will queue all outstanding requests until
the underlying connection is ready. Additionally, it will only keep this
underlying connection in an "idle" state for 60s by default and will
automatically end the underlying connection when it is no longer needed.

From a consumer side this means that you can start sending queries to the
database right away while the underlying connection may still be
outstanding. Because creating this underlying connection may take some
time, it will enqueue all oustanding commands and will ensure that all
commands will be executed in correct order once the connection is ready.
In other words, this "virtual" connection behaves just like a "real"
connection as described in the `ConnectionInterface` and frees you from
having to deal with its async resolution.

If the underlying database connection fails, it will reject all
outstanding commands and will return to the initial "idle" state. This
means that you can keep sending additional commands at a later time which
will again try to open a new underlying connection. Note that this may
require special care if you're using transactions that are kept open for
longer than the idle period.

Note that creating the underlying connection will be deferred until the
first request is invoked. Accordingly, any eventual connection issues
will be detected once this instance is first used. You can use the
`quit()` method to ensure that the "virtual" connection will be soft-closed
and no further commands can be enqueued. Similarly, calling `quit()` on
this instance when not currently connected will succeed immediately and
will not have to wait for an actual underlying connection.

Depending on your particular use case, you may prefer this method or the
underlying `createConnection()` which resolves with a promise. For many
simple use cases it may be easier to create a lazy connection.

The `$url` parameter must contain the database host, optional
authentication, port and database to connect to:

```php
$factory->createLazyConnection('user:secret@localhost:3306/database');
```

Note that both the username and password must be URL-encoded (percent-encoded)
if they contain special characters:

```php
$user = 'he:llo';
$pass = 'p@ss';

$connection = $factory->createLazyConnection(
    rawurlencode($user) . ':' . rawurlencode($pass) . '@localhost:3306/db'
);
```

You can omit the port if you're connecting to default port `3306`:

```php
$factory->createLazyConnection('user:secret@localhost/database');
```

If you do not include authentication and/or database, then this method
will default to trying to connect as user `root` with an empty password
and no database selected. This may be useful when initially setting up a
database, but likely to yield an authentication error in a production system:

```php
$factory->createLazyConnection('localhost');
```

This method respects PHP's `default_socket_timeout` setting (default 60s)
as a timeout for establishing the underlying connection and waiting for
successful authentication. You can explicitly pass a custom timeout value
in seconds (or use a negative number to not apply a timeout) like this:

```php
$factory->createLazyConnection('localhost?timeout=0.5');
```

By default, this method will keep "idle" connection open for 60s and will
then end the underlying connection. The next request after an "idle"
connection ended will automatically create a new underlying connection.
This ensure you always get a "fresh" connection and as such should not be
confused with a "keepalive" or "heartbeat" mechanism, as this will not
actively try to probe the connection. You can explicitly pass a custom
idle timeout value in seconds (or use a negative number to not apply a
timeout) like this:

```php
$factory->createLazyConnection('localhost?idle=0.1');
```

### ConnectionInterface

The `ConnectionInterface` represents a connection that is responsible for
communicating with your MySQL server instance, managing the connection state
and sending your database queries.

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

The `ping(): PromiseInterface<void, Exception>` method can be used to
check that the connection is alive.

This method returns a promise that will resolve (with a void value) on
success or will reject with an `Exception` on error. The MySQL protocol
is inherently sequential, so that all commands will be performed in order
and outstanding command will be put into a queue to be executed once the
previous queries are completed.

```php
$connection->ping()->then(function () {
    echo 'OK' . PHP_EOL;
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

#### quit()

The `quit(): PromiseInterface<void, Exception>` method can be used to
quit (soft-close) the connection.

This method returns a promise that will resolve (with a void value) on
success or will reject with an `Exception` on error. The MySQL protocol
is inherently sequential, so that all commands will be performed in order
and outstanding commands will be put into a queue to be executed once the
previous commands are completed.

```php
$connection->query('CREATE TABLE test ...');
$connection->quit();
```

#### close()

The `close(): void` method can be used to
force-close the connection.

Unlike the `quit()` method, this method will immediately force-close the
connection and reject all oustanding commands.

```php
$connection->close();
```

Forcefully closing the connection will yield a warning in the server logs
and should generally only be used as a last resort. See also
[`quit()`](#quit) as a safe alternative.

#### Events

Besides defining a few methods, this interface also implements the
`EventEmitterInterface` which allows you to react to certain events:

##### error event

The `error` event will be emitted once a fatal error occurs, such as
when the connection is lost or is invalid.
The event receives a single `Exception` argument for the error instance.

```php
$connection->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

This event will only be triggered for fatal errors and will be followed
by closing the connection. It is not to be confused with "soft" errors
caused by invalid SQL queries.

##### close event

The `close` event will be emitted once the connection closes (terminates).

```php
$connection->on('close', function () {
    echo 'Connection closed' . PHP_EOL;
});
```

See also the [`close()`](#close) method.

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require react/mysql:^0.5.4
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
    -e MYSQL_USER=test -e MYSQL_PASSWORD=test mysql:5
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
