# Changelog

## 0.5.4 (2019-05-21)

*   Fix: Do not start idle timer when lazy connection is already closed.
    (#110 by @clue)

*   Fix: Fix explicit `close()` on lazy connection when connection is active.
    (#109 by @clue)

## 0.5.3 (2019-04-03)

*   Fix: Ignore unsolicited server error when not executing any commands.
    (#102 by @clue)

*   Fix: Fix decoding URL-encoded special characters in credentials from database connection URI.
    (#98 and #101 by @clue)

## 0.5.2 (2019-02-05)

*   Fix: Fix `ConnectionInterface` return type hint in `Factory`.
    (#93 by @clue)

*   Minor documentation typo fix and improve test suite to test against PHP 7.3,
    add forward compatibility with PHPUnit 7 and use legacy PHPUnit 5 on HHVM.
    (#92 and #94 by @clue)

## 0.5.1 (2019-01-12)

*   Fix: Fix "bad handshake" error when connecting without database name.
    (#91 by @clue)

## 0.5.0 (2018-11-28)

A major feature release with a significant API improvement!

This update does not involve any BC breaks, but we figured the new API provides
significant features that warrant a major version bump. Existing code will
continue to work without changes, but you're highly recommended to consider
using the new lazy connections as detailed below.

*   Feature: Add new `createLazyConnection()` method to only connect on demand and
    implement "idle" timeout to close underlying connection when unused.
    (#87 and #88 by @clue)

    ```php
    // new
    $connection = $factory->createLazyConnection($url);
    $connection->query(…);
    ```

    This method immediately returns a "virtual" connection implementing the
    [`ConnectionInterface`](README.md#connectioninterface) that can be used to
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

*   Feature: Support connection timeouts.
    (#86 by @clue)

## 0.4.1 (2018-10-18)

*   Feature: Support cancellation of pending connection attempts.
    (#84 by @clue)

*   Feature: Add `warningCount` to `QueryResult`.
    (#82 by @legionth)

*   Feature: Add exception message for invalid MySQL URI.
    (#80 by @CharlotteDunois)

*   Fix: Fix parsing error message during handshake (Too many connections).
    (#83 by @clue)

## 0.4.0 (2018-09-21)

A major feature release with a significant documentation overhaul and long overdue API cleanup!

This update involves a number of BC breaks due to various changes to make the
API more consistent with the ReactPHP ecosystem. In particular, this now uses
promises consistently as return values instead of accepting callback functions
and this now offers an additional streaming API for processing very large result
sets efficiently.

We realize that the changes listed below may seem a bit overwhelming, but we've
tried to be very clear about any possible BC breaks. See below for changes you
have to take care of when updating from an older version.

*   Feature / BC break: Add Factory to simplify connecting and keeping connection state,
    mark `Connection` class as internal and remove `connect()` method.
    (#64 by @clue)

    ```php
    // old
    $connection = new Connection($loop, $options);
    $connection->connect(function (?Exception $error, $connection) {
        if ($error) {
            // an error occured while trying to connect or authorize client
        } else {
            // client connection established (and authenticated)
        }
    });

    // new
    $factory = new Factory($loop);
    $factory->createConnection($url)->then(
        function (ConnectionInterface $connection) {
            // client connection established (and authenticated)
        },
        function (Exception $e) {
            // an error occured while trying to connect or authorize client
        }
    );
    ```

*   Feature / BC break: Use promises for `query()` method and resolve with `QueryResult` on success and
    and mark all commands as internal and move its base to Commands namespace.
    (#61 and #62 by @clue)

    ```php
    // old
    $connection->query('CREATE TABLE test');
    $connection->query('DELETE FROM user WHERE id < ?', $id);
    $connection->query('SELECT * FROM user', function (QueryCommand $command) {
        if ($command->hasError()) {
            echo 'Error: ' . $command->getError()->getMessage() . PHP_EOL;
        } elseif (isset($command->resultRows)) {
            var_dump($command->resultRows);
        }
    });

    // new
    $connection->query('CREATE TABLE test');
    $connection->query('DELETE FROM user WHERE id < ?', [$id]);
    $connection->query('SELECT * FROM user')->then(function (QueryResult $result) {
        var_dump($result->resultRows);
    }, function (Exception $error) {
        echo 'Error: ' . $error->getMessage() . PHP_EOL;
    });
    ```

*   Feature / BC break: Add new `queryStream()` method to stream result set rows and
    remove undocumented "results" event.
    (#57 and #77 by @clue)

    ```php
    $stream = $connection->queryStream('SELECT * FROM users');

    $stream->on('data', function ($row) {
        var_dump($row);
    });
    $stream->on('end', function () {
        echo 'DONE' . PHP_EOL;
    });
    ```

*   Feature / BC break: Rename `close()` to `quit()`, use promises for `quit()` method and
    add new `close()` method to force-close the connection.
    (#65 and #76 by @clue)

    ```php
    // old: soft-close/quit
    $connection->close(function () {
        echo 'closed';
    });

    // new: soft-close/quit
    $connection->quit()->then(function () {
        echo 'closed';
    });

    // new: force-close
    $connection->close();
    ```

*   Feature / BC break: Use promises for `ping()` method and resolve with void value on success.
    (#63 and #66 by @clue)

    ```php
    // old
    $connection->ping(function ($error, $connection) {
        if ($error) {
            echo 'Error: ' . $error->getMessage() . PHP_EOL;
        } else {
            echo 'OK' . PHP_EOL;
        }
    });

    // new 
    $connection->ping(function () {
        echo 'OK' . PHP_EOL;
    }, function (Exception $error) {
        echo 'Error: ' . $error->getMessage() . PHP_EOL;
    });
    ```

*   Feature / BC break: Define events on ConnectionInterface
    (#78 by @clue)

*   BC break: Remove unneeded `ConnectionInterface` methods `getState()`,
    `getOptions()`, `setOptions()` and `getServerOptions()`, `selectDb()` and `listFields()` dummy.
    (#60 and #68 by @clue)

*   BC break: Mark all protocol logic classes as internal and move to new Io namespace.
    (#53 and #62 by @clue)

*   Fix: Fix executing queued commands in the order they are enqueued
    (#75 by @clue)

*   Fix: Fix reading all incoming response packets until end
    (#59 by @clue)

*   [maintenance] Internal refactoring to simplify connection and authentication logic
    (#69 by @clue)
*   [maintenance] Internal refactoring to remove unneeded references from Commands
    (#67 by @clue)
*   [maintenance] Internal refactoring to remove unneeded EventEmitter implementation and circular references
    (#56 by @clue)
*   [maintenance] Refactor internal parsing logic to separate Buffer class, remove dead code and improve performance
    (#54 by @clue)

## 0.3.3 (2018-06-18)

*   Fix: Reject pending commands if connection is closed
    (#52 by @clue)

*   Fix: Do not support multiple statements for security and API reasons
    (#51 by @clue)

*   Fix: Fix reading empty rows containing only empty string columns 
    (#46 by @clue)

*   Fix: Report correct field length for fields longer than 16k chars
    (#42 by @clue)

*   Add quickstart example and interactive CLI example
    (#45 by @clue)

## 0.3.2 (2018-04-04)

*   Fix: Fix parameter binding if query contains question marks
    (#40 by @clue)

*   Improve test suite by simplifying test structure, improve test isolation and remove dbunit
    (#39 by @clue)

## 0.3.1 (2018-03-26)

*   Feature: Forward compatibility with upcoming ReactPHP components
    (#37 by @clue)

*   Fix: Consistent `connect()` behavior for all connection states
    (#36 by @clue)

*   Fix: Report connection error to `connect()` callback
    (#35 by @clue)

## 0.3.0 (2018-03-13)

*   This is now a community project managed by @friends-of-reactphp. Thanks to
    @bixuehujin for releasing this project under MIT license and handing over!
    (#12 and #33 by @bixuehujin and @clue)

*   Feature / BC break: Update react/socket to v0.8.0
    (#21 by @Fneufneu)

*   Feature: Support passing custom connector and
    load system default DNS config by default
    (#24 by @flow-control and #30 by @clue)

*   Feature: Add `ConnectionInterface` with documentation
    (#26 by @freedemster)

*   Fix: Last query param is lost if no callback is given
    (#22 by @Fneufneu)

*   Fix: Fix memory increase (memory leak due to keeping incoming receive buffer)
    (#17 by @sukui)

*   Improve test suite by adding test instructions and adding Travis CI
    (#34 by @clue and #25 by @freedemster)

*   Improve documentation
    (#8 by @ovr and #10 by @RafaelKa)

## 0.2.0 (2014-10-15)

*   Now compatible with ReactPHP v0.4

## 0.1.0 (2014-02-18)

*   First tagged release (ReactPHP v0.3)
