# Changelog

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
