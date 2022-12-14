# Changelog

## 2.0.0

* Set minimum PHP version to 8.0
* The driver uses now yield to return a fetched row this means
* Added DoctrineConnectionInterface which has a Doctrine DBAL compatible API

## 1.0.0

* Move integration tests to GitHub actions and remove Jenkins actions
* Throw CommitException in case of a rollback inside a nested transaction
* Throw TableNotFoundException exception
* Throw QueryException in case prepared statement is false or the query execution failed
* Add MockConnection
