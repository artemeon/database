<?php

/*
 * This file is part of the Artemeon Core - Web Application Framework.
 *
 * (c) Artemeon <www.artemeon.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Artemeon\Database;

use Artemeon\Database\Exception\AddColumnException;
use Artemeon\Database\Exception\ChangeColumnException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Exception\RemoveColumnException;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\Table;
use Artemeon\Database\Schema\TableIndex;
use Generator;

/**
 * Interface of our internal database abstraction layer.
 * If possible please use the new methods from the DoctrineConnectionInterface
 */
interface ConnectionInterface extends DoctrineConnectionInterface
{
    /**
     * Legacy method to execute a query and return the result, please use one of the newer fetch* or iterate* methods
     * Note the new fetch* and iterate* methods dont use the dbsafeParams method, this means there is no htmlspecialchars handling
     * also there is no query cache handling, so you need to cache the results if needed in your service
     *
     * Method to get an array of rows for a given query from the database.
     * Makes use of prepared statements.
     *
     * @throws QueryException
     * @see fetchAllAssociative
     */
    public function getPArray(string $query, array $params = [], ?int $start = null, ?int $end = null, bool $cache = true, array $escapes = []): array;

    /**
     * Legacy method to execute a query and return the result, please use one of the newer fetch* or iterate* methods
     * Note the new fetch* and iterate* methods dont use the dbsafeParams method, this means there is no htmlspecialchars handling
     * also there is no query cache handling, so you need to cache the results if needed in your service
     *
     * Returns one row from a result-set.
     * Makes use of prepared statements.
     *
     * @throws QueryException
     * @see fetchAssociative
     */
    public function getPRow(string $query, array $params = [], int $number = 0, bool $cache = true, array $escapes = []): array;

    /**
     * Retrieves a single row of the referenced table, returning the requested columns and filtering by the given identifier(s).
     *
     * @param string $tableName the table name from which to select the row
     * @param array $columns a flat list of column names to select
     * @param array $identifiers mapping of column name to value to search for (e.g. ["id" => 1])
     * @param bool $cached whether a previously selected result can be reused
     * @param array|null $escapes which parameters to escape (described in {@see dbsafeParams})
     * @throws QueryException
     */
    public function selectRow(string $tableName, array $columns, array $identifiers, bool $cached = true, ?array $escapes = []): ?array;

    /**
     * Legacy method to execute a query and return the result, please use one of the newer fetch* or iterate* methods.
     *
     * Returns a generator, which can be used to iterate over a section of the query without loading the complete data
     * into the memory. This can be used to query big result sets i.e. on installation update.
     * Make sure to have an ORDER BY in the statement, otherwise the chunks may use duplicate entries depending on the RDBMS.
     *
     * NOTE if the loop, which consumes the generator reduces the result set i.e. you delete for each result set all
     * entries then you need to set paging to false. In this mode we always query the first 0 to chunk size rows, since
     * the loop reduces the result set we don't need to move the start and end values forward. NOTE if you set $paging to
     * false and don't modify the result set you will get an endless loop, so you must get sure that in the end the
     * result set will be empty.
     *
     * @throws QueryException
     * @see iterateAssociative
     */
    public function getGenerator(string $query, array $params = [], int $chunkSize = 2048, bool $paging = true): Generator;

    /**
     * Legacy method to execute a query please use executeStatement
     *
     * Sending a prepared statement to the database
     *
     * @param array $escapes An array of booleans for each param, used to block the escaping of html-special chars.
     *                       If not passed, all params will be cleaned.
     * @throws QueryException
     * @see executeStatement
     */
    public function _pQuery(string $query, array $params = [], array $escapes = []): bool;

    /**
     * Returns the number of affected rows from the last _pQuery call.
     */
    public function getAffectedRowsCount(): int;

    /**
     * Creates a single query in order to insert multiple rows at one time.
     * For most databases, this will create s.th. like
     * INSERT INTO $table ($columns) VALUES (?, ?), (?, ?)...
     *
     * @param string[] $columns
     * @throws QueryException
     */
    public function multiInsert(string $tableName, array $columns, array $valueSets, ?array $escapes = null): bool;

    /**
     * Fires an insert or update of a single record. It is up to the database (driver)
     * to detect whether a row is already present or not.
     * Please note: since some DBRMs fire a delete && insert, make sure to pass ALL columns and values,
     * otherwise data might be lost. And: params are sent to the database unescaped.
     *
     * @throws QueryException
     */
    public function insertOrUpdate(string $tableName, array $columns, array $values, array $primaryColumns): bool;

    public function isConnected(): bool;

    /**
     * Starts a transaction.
     *
     * @deprecated Use {@see DoctrineConnectionInterface::beginTransaction()} instead.
     */
    public function transactionBegin(): void;

    /**
     * Ends a transaction successfully.
     *
     * @deprecated Use {@see DoctrineConnectionInterface::commit()} instead.
     */
    public function transactionCommit(): void;

    /**
     * Rollback of the current transaction.
     *
     * @deprecated Use {@see DoctrineConnectionInterface::rollBack()} instead.
     */
    public function transactionRollback(): void;

    /**
     * Returns whether this connection uses a specific driver. In general please don't use this method
     * since it makes your code dependent on a specific driver. This is only intended for rare cases i.e.
     * to execute a migration for a specific database type.
     */
    public function hasDriver(string $class): bool;

    /**
     * Returns all tables used by the project.
     *
     * @throws QueryException
     */
    public function getTables(): array;

    /**
     * Fetches extensive information per database table.
     *
     * @throws QueryException
     */
    public function getTableInformation(string $tableName): Table;

    /**
     * Returns the db-specific datatype for the Kajona internal datatype.
     */
    public function getDatatype(DataType $type): string;

    /**
     * Used to send a create table statement to the database
     * By passing the query through this method, the driver can
     * add db-specific commands.
     * The array of fields should have the following structure
     * $array[string columnName] = [string data-type, boolean isNull [, default (only if not null)]]
     * whereas data-type is one of the following:
     *  - int
     *  - long
     *  - double
     *  - char10
     *  - char20
     *  - char100
     *  - char254
     *  - char500
     *  - text
     *  - longtext
     *
     * @throws QueryException
     */
    public function createTable(string $tableName, array $columns, array $keys, array $indices = []): bool;

    /**
     * Drops a table from the database. Checks also whether the table already exists.
     *
     * @throws QueryException
     */
    public function dropTable(string $tableName): void;

    /**
     * Generates a tables as configured by the passed Table definition. Includes all metadata such as
     * primary keys, indexes and columns.
     *
     * @throws QueryException
     */
    public function generateTableFromMetadata(Table $table): void;

    /**
     * Creates a new index on the provided table over the given columns. If unique is true we create a unique index
     * where each index can only occur once in the table.
     *
     * @throws QueryException
     */
    public function createIndex(string $tableName, string $name, array $columns, bool $unique = false): bool;

    /**
     * Removes an index from the database / table.
     *
     * @throws QueryException
     */
    public function deleteIndex(string $table, string $index): bool;

    /**
     * Adds an index to a table based on the import / export format.
     *
     * @throws QueryException
     * @internal
     */
    public function addIndex(string $table, TableIndex $index): bool;

    /**
     * Checks whether the table has an index with the provided name
     *
     * @throws QueryException
     */
    public function hasIndex(string $tableName, string $name): bool;

    /**
     * Renames a table.
     *
     * @throws QueryException
     */
    public function renameTable(string $oldName, string $newName): bool;

    /**
     * Changes a single column, e.g. the datatype. Note in case you only change the column type you should be aware that
     * not all database engines support changing the type freely. Most engines disallow changing the type in case you
     * would lose data i.e. on Oracle it is not possible to change from longtext to char(10) since then the DB engine
     * may need to truncate some rows.
     *
     * @throws ChangeColumnException
     */
    public function changeColumn(string $tableName, string $oldColumnName, string $newColumnName, DataType $newDataType): bool;

    /**
     * Adds a column to a table.
     *
     * @throws AddColumnException
     */
    public function addColumn(string $table, string $column, DataType $dataType, ?bool $nullable = null, ?string $default = null): bool;

    /**
     * Removes a column from a table.
     *
     * @throws RemoveColumnException
     */
    public function removeColumn(string $tableName, string $column): bool;

    /**
     * Checks whether a table has a specific column.
     *
     * @throws QueryException
     */
    public function hasColumn(string $tableName, string $column): bool;

    /**
     * Checks whether the provided table exists.
     *
     * @throws QueryException
     */
    public function hasTable(string $tableName): bool;

    /**
     * Allows the db-driver to add database-specific surroundings to column-names.
     * E.g. needed by the MySQL-drivers.
     */
    public function encloseColumnName(string $column): string;

    /**
     * Allows the db-driver to add database-specific surroundings to table-names.
     * E.g. needed by the MySQL-drivers.
     */
    public function encloseTableName(string $tableName): string;

    /**
     * Helper to replace all param-placeholder with the matching value, only to be used
     * to render a debuggable-statement.
     */
    public function prettifyQuery(string $query, array $params): string;

    /**
     * Appends a limit expression to the provided query.
     */
    public function appendLimitExpression(string $query, int $start, int $end): string;

    public function getConcatExpression(array $parts): string;

    public function getLeastExpression(array $parts): string;

    /**
     * Builds a query expression to retrieve a substring of the given column name or value.
     *
     * The offset of the substring inside of the value must be given as 1-based index. If a length is given, only up to
     * this number of characters are extracted; if no length is given, everything to the end of the value is extracted.
     * *Note*: Negative offsets or lengths are not guaranteed to work across different database drivers.
     */
    public function getSubstringExpression(string $value, int $offset, ?int $length): string;

    /**
     * Returns the database-specific string-length expression, e.g. LEN() or LENGTH().
     * Pass the value to be counted (e.g. a column name) by param
     *
     */
    public function getStringLengthExpression(string $targetString): string;

    /**
     * Converts a PHP value to a value, which can be inserted into a table. I.e. it truncates the value to
     * the fitting length for the provided datatype.
     */
    public function convertToDatabaseValue(mixed $value, DataType $type): mixed;

    /**
     * Queries the current db-driver about common information.
     */
    public function getDbInfo(): array;

    /**
     * Returns an array of all queries.
     */
    public function getQueries(): array;

    /**
     * Returns the number of queries sent to the database including those solved by the cache.
     */
    public function getNumber(): int;

    /**
     * Returns the number of queries solved by the cache.
     */
    public function getNumberCache(): int;

    /**
     * Returns the number of items currently in the query-cache.
     */
    public function getCacheSize(): int;
}
