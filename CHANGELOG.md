# Changelog

## 3.0.0

- Bumps `symfony/process` to `^6.0.0`.
- Renames `ConnectionInterface::transactionBegin()` to `ConnectionInterface::beginTransaction()`.
- Renames `ConnectionInterface::transactionCommit()` to `ConnectionInterface::commitTransaction()`.
- Renames `ConnectionInterface::transactionRollback()` to `ConnectionInterface::rollbackTransaction()`.
- Renames `DriverInterface::transactionBegin()` to `DriverInterface::beginTransaction()`.
- Renames `DriverInterface::transactionCommit()` to `DriverInterface::commitTransaction()`.
- Renames `DriverInterface::transactionRollback()` to `DriverInterface::rollbackTransaction()`.
- Cleans up code

## 2.0.2

* Add missing lazy connect

## 2.0.0

* Set minimum PHP version to 8.0
* The driver uses now yield to return a fetched row
* Added DoctrineConnectionInterface which has a Doctrine DBAL compatible API

## 1.0.0

* Move integration tests to GitHub actions and remove Jenkins actions
* Throw CommitException in case of a rollback inside a nested transaction
* Throw TableNotFoundException exception
* Throw QueryException in case prepared statement is false or the query execution failed
* Add MockConnection
