# Changelog

## 3.2.1

- Automatically convert backend enums to the value so that a user can provide an enum class as parameter
- Streamline params handling, all new fetch* and iterate* methods are without auto-escaping

## 3.0.0

- Bumps the minimum required PHP version to `8.1`.
- Deprecates `ConnectionInterface::transactionBegin()` in favor of `DoctrineConnectionInterface::beginTransaction()`.
- Deprecates `ConnectionInterface::transactionCommit()` in favor of `DoctrineConnectionInterface::commit()`.
- Deprecates `ConnectionInterface::transactionRollback()` in favor of `DoctrineConnectionInterface::rollBack()`.
- Deprecates `DriverInterface::transactionBegin()` in favor of `DriverInterface::beginTransaction()`.
- Deprecates `DriverInterface::transactionCommit()` in favor of `DriverInterface::commit()`.
- Deprecates `DriverInterface::transactionRollback()` in favor of `DriverInterface::rollBack()`.
- `DataType` is a (PHP 8.1) enum now.
  - The deprecated `DataType::STR_TYPE_LONG` is removed.
  - The deprecated `DataType::STR_TYPE_DOUBLE` is removed.
  - `DataType::STR_TYPE_INT` is now `DataType::INT`.
  - `DataType::STR_TYPE_BIGINT` is now `DataType::BIGINT`.
  - `DataType::STR_TYPE_FLOAT` is now `DataType::FLOAT`.
  - `DataType::STR_TYPE_CHAR10` is now `DataType::CHAR10`.
  - `DataType::STR_TYPE_CHAR20` is now `DataType::CHAR20`.
  - `DataType::STR_TYPE_CHAR100` is now `DataType::CHAR100`.
  - `DataType::STR_TYPE_CHAR254` is now `DataType::CHAR254`.
  - `DataType::STR_TYPE_CHAR500` is now `DataType::CHAR500`.
  - `DataType::STR_TYPE_TEXT` is now `DataType::TEXT`.
  - `DataType::STR_TYPE_LONGTEXT` is now `DataType::LONGTEXT`.
- Various methods require the new `DataType` enum as a parameter instead of a plain string now.
- Cleans up code

## 2.1.0

* Add support for `symfony/process@^6.0.0`.

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
