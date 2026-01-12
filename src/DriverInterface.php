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

use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\Table;
use Artemeon\Database\Schema\TableIndex;
use Generator;

/**
 * Interface to specify the layout of db-drivers.
 * Implement this interface, if you want to provide a db-layer for Kajona.
 */
interface DriverInterface
{
    /**
     * This method makes sure to connect to the database properly.
     *
     * @throws ConnectionException
     */
    public function dbconnect(ConnectionParameters $params): bool;

    /**
     * Closes the connection to the database.
     */
    public function dbclose(): void;

    /**
     * Creates a single query in order to insert multiple rows at one time.
     * For most databases, this will create s.th. like
     * INSERT INTO $table ($columns) VALUES (?, ?), (?, ?)...
     * Please note that this method is used to create the query itself, based on the Kajona-internal syntax.
     * The query is fired to the database by Database.
     *
     * @param list<string> $columns
     * @param list<array<array-key, mixed>> $valueSets
     * @param list<bool> $escapes
     */
    public function triggerMultiInsert(string $table, array $columns, array $valueSets, ConnectionInterface $database, ?array $escapes): bool;

    /**
     * Fires an insert or update of a single record. It is up to the database (driver)
     * to detect whether a row is already present or not.
     * Please note: since some dbrms fire a delete && insert, make sure to pass ALL colums and values,
     * otherwise data might be lost.
     *
     * @param list<string> $columns
     * @param list<mixed> $values
     * @param list<string> $primaryColumns
     */
    public function insertOrUpdate(string $table, array $columns, array $values, array $primaryColumns): bool;

    /**
     * Sends a prepared statement to the database. All params must be represented by the "?" char.
     * The params themselves are stored using the second params using the matching order.
     *
     * @param list<mixed> $params
     *
     * @throws QueryException
     */
    public function _pQuery(string $query, array $params): bool;

    /**
     * This method is used to retrieve an array of result-sets from the database using
     * a prepared statement.
     *
     * @param list<mixed> $params
     *
     * @throws QueryException
     */
    public function getPArray(string $query, array $params): Generator;

    /**
     * Returns the last error reported by the database.
     * Is being called after unsuccessful queries.
     */
    public function getError(): string;

    /**
     * Returns ALL tables in the database currently connected to.
     * The method should return an array using the following keys:
     * name => Table name.
     *
     * @return list<array{name:string}>
     */
    public function getTables(): array;

    /**
     * Fetches the full table information as retrieved from the rdbms.
     */
    public function getTableInformation(string $tableName): Table;

    /**
     * Used to send a CREATE table statement to the database
     * By passing the query through this method, the driver can add db-specific commands.
     *
     * @param array<non-empty-string, array{0: DataType, 1: bool, 2?: mixed}> $columns
     * @param list<string> $primaryKeys
     */
    public function createTable(string $name, array $columns, array $primaryKeys): bool;

    /**
     * Creates a new index on the provided table over the given columns. If unique is true we create a unique index
     * where each index can only occur once in the table.
     *
     * @param list<string> $columns
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false): bool;

    /**
     * Deletes an index from the database.
     */
    public function deleteIndex(string $table, string $index): bool;

    /**
     * Adds a new index to the provided table.
     */
    public function addIndex(string $table, TableIndex $index): bool;

    /**
     * Checks whether the table has an index with the provided name.
     */
    public function hasIndex(string $table, string $name): bool;

    /**
     * Returns whether a column exists on a table.
     */
    public function hasColumn(string $tableName, string $columnName): bool;

    /**
     * Renames a table.
     */
    public function renameTable(string $oldName, string $newName): bool;

    /**
     * Changes a single column, e.g. the datatype.
     */
    public function changeColumn(string $table, string $oldColumnName, string $newColumnName, DataType $newDataType): bool;

    /**
     * Adds a column to a table.
     */
    public function addColumn(string $table, string $column, DataType $dataType, ?bool $nullable = null, ?string $default = null): bool;

    /**
     * Removes a column from a table.
     */
    public function removeColumn(string $table, string $column): bool;

    /**
     * Starts a transaction.
     */
    public function beginTransaction(): void;

    /**
     * Starts a transaction.
     *
     * @deprecated Use {@see DriverInterface::beginTransaction()} instead.
     */
    public function transactionBegin(): void;

    /**
     * Ends a successful operation by committing the transaction.
     */
    public function commit(): void;

    /**
     * Ends a successful operation by committing the transaction.
     *
     * @deprecated Use {@see DriverInterface::commit()} instead.
     */
    public function transactionCommit(): void;

    /**
     * Ends a non-successful transaction by using a rollback.
     */
    public function rollBack(): void;

    /**
     * Ends a non-successful transaction by using a rollback.
     *
     * @deprecated Use {@see DriverInterface::rollBack()} instead.
     */
    public function transactionRollback(): void;

    /**
     * Returns an array of key value pairs with infos about the current database
     * The array returned should have tho following structure:
     *  property name => value.
     *
     * @return array<string, mixed>
     */
    public function getDbInfo(): array;

    /**
     * Creates an db-dump using the given filename. The filename is relative to _realpath_
     * The dump must include, and ONLY include the pass tables.
     *
     * @param string &$fileName passed by reference so that the driver is able to update the filename, e.g. in order to add a .gz suffix.
     * @param list<string> $tables
     */
    public function dbExport(string &$fileName, array $tables): bool;

    /**
     * Imports the given db-dump file to the database. The filename ist relative to _realpath_.
     */
    public function dbImport(string $fileName): bool;

    /**
     * Allows the db-driver to add database-specific surroundings to column-names.
     * E.g. needed by the mysql-drivers.
     */
    public function encloseColumnName(string $column): string;

    /**
     * Allows the db-driver to add database-specific surroundings to table-names.
     * E.g. needed by the mysql-drivers.
     */
    public function encloseTableName(string $table): string;

    /**
     * Returns the db-specific datatype for the kajona internal datatype.
     */
    public function getDatatype(DataType $type): string;

    /**
     * A method triggered in special cases in order to
     * have even the caches stored at the db-driver being flushed.
     * This could get important in case of schema updates since precompiled queries may get invalid due
     * to updated table definitions.
     */
    public function flushQueryCache(): void;

    public function escape(mixed $value): mixed;

    /**
     * Appends a limit expression to the provided query. The start and end parameter are the positions of the start and
     * end row, which you want to include in your result-set. I.e. to return a single row use 0, 0. To return the first
     * 8 rows use 0, 7.
     */
    public function appendLimitExpression(string $query, int $start, int $end): string;

    /**
     * Returns a query expression, which concatenates different values. This can bei either column names or strings.
     * <code>
     *  $connection->getConcatExpression(['user_kajona.user_forename', '\' \'', 'user_kajona.user_name'])
     * </code>.
     *
     * @param list<string> $parts
     */
    public function getConcatExpression(array $parts): string;

    /**
     * Returns the number of affected rows from the last _pQuery call.
     */
    public function getAffectedRowsCount(): int;

    /**
     * Default implementation to detect if a driver handles compression.
     * By default, db-drivers us a piped gzip / gunzip command when creating / restoring dumps on UNIX.
     * If running on windows, the Database class handles the compression / decompression.
     */
    public function handlesDumpCompression(): bool;

    /**
     * Convert a PHP value to a value, which can be inserted into a table. I.e. it truncates the value to
     * the fitting length for the provided datatype.
     */
    public function convertToDatabaseValue(mixed $value, DataType $type): mixed;

    /**
     * Returns a "LEAST()" query expression, which selects the minimum value of given columns.
     * <code>
     *  $connection->getLeastExpression(['column1','column2', ...])
     * </code>.
     *
     * @param list<string> $parts
     */
    public function getLeastExpression(array $parts): string;

    /**
     * Builds a query expression to retrieve a substring of the given column name or value.
     *
     * The offset of the substring inside the value must be given as 1-based index. If a length is given, only up to
     * this number of characters are extracted; if no length is given, everything to the end of the value is extracted.
     * *Note*: It is not guaranteed that negative offsets or lengths work across different database drivers.
     */
    public function getSubstringExpression(string $value, int $offset, ?int $length): string;

    /**
     * Returns the database-specific string-length expression, e.g. LEN() or LENGTH().
     * Pass the value to be counted (e.g. a column name) by param.
     */
    public function getStringLengthExpression(string $targetString): string;

    /**
     * Generates a SQL snippet that extracts a value from a JSON string or returns the string itself.
     *
     * Given a column that contains either JSON data or simple strings, this method creates a SQL snippet
     * that extracts the value associated with a specified key from the JSON data if the column contains
     * JSON. If the column contains a simple string, it returns the string itself.
     *
     * Example Usage:
     * $snippet = $this->getJsonColumnExpression('data', 'de');
     * "SELECT $snippet AS translation FROM $table;"
     *
     *   - For a column 'data' with JSON content '{"de":"Translation a","en":"Translation b"}'
     *   - and key 'de', the snippet will extract the value 'Translation a'.
     *   - If the column contains a simple string 'Translation', the snippet will return it directly.
     *
     * @param string $column The name of the column containing JSON data or simple strings.
     * @param string $key The key to extract from the JSON data.
     * @return string The SQL snippet for extracting the value.
     */
    public function getJsonColumnExpression(string $column, string $key): string;

    /**
     * Generates the SQL snippet to extract the nth element from the end
     * of a slash-separated string in the specified column.
     *
     *  Example usage:
     *  $snippet = $this->getNthLastElementFromSlug('slug_column', 3);
     *  "LEFT JOIN table ON table.id = $slugSnippet"
     *
     * @param string $column The name of the column containing the slash-separated string.
     * @param int $position The position from the end of the string (e.g., 3 for the third last element).
     * @return string The generated SQL snippet.
     */
    public function getNthLastElementFromSlug(string $column, int $position): string;
}
