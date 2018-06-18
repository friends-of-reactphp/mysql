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

$connection->query('SELECT * FROM book', function (QueryCommand $command) {
    if ($command->hasError()) {
        $error = $command->getError();
        echo 'Error: ' . $error->getMessage() . PHP_EOL;
    } else {
        print_r($command->resultFields);
        print_r($command->resultRows);
        echo count($command->resultRows) . ' row(s) in set' . PHP_EOL;
    }
});

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
