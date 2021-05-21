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

/**
 * @since 7.3
 */
interface ConnectionInterface
{
    /**
     * Method to get an array of rows for a given query from the database.
     * Makes use of prepared statements.
     *
     * @param string $strQuery
     * @param array $arrParams
     * @param int|null $intStart
     * @param int|null $intEnd
     * @param bool $bitCache
     * @return array
     * @throws QueryException
     * @since 3.4
     */
    public function getPArray($strQuery, $arrParams = [], $intStart = null, $intEnd = null, $bitCache = true);

    /**
     * Returns one row from a result-set.
     * Makes use of prepared statements.
     *
     * @param string $strQuery
     * @param array $arrParams
     * @param int $intNr
     * @param bool $bitCache
     * @return array
     * @throws QueryException
     */
    public function getPRow($strQuery, $arrParams = [], $intNr = 0, $bitCache = true);

    /**
     * Retrieves a single row of the referenced table, returning the requested columns and filtering by the given identifier(s).
     *
     * @param string $tableName the table name from which to select the row
     * @param array $columns a flat list of column names to select
     * @param array $identifiers mapping of column name to value to search for (e.g. ["id" => 1])
     * @param bool $cached whether a previously selected result can be reused
     * @return array|null
     * @throws QueryException
     */
    public function selectRow(string $tableName, array $columns, array $identifiers, bool $cached = true): ?array;

    /**
     * Returns a generator which can be used to iterate over a section of the query without loading the complete data
     * into the memory. This can be used to query big result sets i.e. on installation update.
     * Make sure to have an ORDER BY in the statement, otherwise the chunks may use duplicate entries depending on the RDBMS.
     *
     * NOTE if the loop which consumes the generator reduces the result set i.e. you delete for each result set all
     * entries then you need to set paging to false. In this mode we always query the first 0 to chunk size rows, since
     * the loop reduces the result set we dont need to move the start and end values forward. NOTE if you set $paging to
     * false and dont modify the result set you will get an endless loop, so you must get sure that in the end the
     * result set will be empty.
     *
     * @param string $query
     * @param array $params
     * @param int $chunkSize
     * @param bool $paging
     * @return \Generator
     * @throws QueryException
     */
    public function getGenerator($query, array $params = [], $chunkSize = 2048, $paging = true);

    /**
     * Sending a prepared statement to the database
     *
     * @param string $strQuery
     * @param array $arrParams
     * @return bool
     * @throws QueryException
     * @since 3.4
     */
    public function _pQuery($strQuery, $arrParams = []);

    /**
     * Returns the number of affected rows from the last _pQuery call
     *
     * @return integer
     */
    public function getIntAffectedRows();

    /**
     * Creates a simple insert for a single row where the values parameter is an associative array with column names to
     * value mapping
     *
     * @param string $tableName
     * @param array $values
     * @return bool
     * @throws QueryException
     */
    public function insert(string $tableName, array $values);

    /**
     * Creates a single query in order to insert multiple rows at one time.
     * For most databases, this will create s.th. like
     * INSERT INTO $strTable ($arrColumns) VALUES (?, ?), (?, ?)...
     *
     * @param string $strTable
     * @param string[] $arrColumns
     * @param array $arrValueSets
     * @return bool
     * @throws QueryException
     */
    public function multiInsert(string $strTable, array $arrColumns, array $arrValueSets);

    /**
     * Fires an insert or update of a single record. it's up to the database (driver)
     * to detect whether a row is already present or not.
     * Please note: since some dbrms fire a delete && insert, make sure to pass ALL columns and values,
     * otherwise data might be lost. And: params are sent to the datebase unescaped.
     *
     * @param $strTable
     * @param $arrColumns
     * @param $arrValues
     * @param $arrPrimaryColumns
     * @return bool
     * @throws QueryException
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns);

    /**
     * Updates a row on the provided table by the identifier columns
     *
     * @param string $tableName
     * @param array $values
     * @param array $identifier
     * @return bool
     * @throws QueryException
     */
    public function update(string $tableName, array $values, array $identifier): bool;

    /**
     * Deletes a row on the provided table by the identifier columns
     *
     * @param string $tableName
     * @param array $identifier
     * @return bool
     * @throws QueryException
     */
    public function delete(string $tableName, array $identifier): bool;

    /**
     * @return bool
     */
    public function getBitConnected();

    /**
     * Starts a transaction
     */
    public function transactionBegin();

    /**
     * Ends a tx successfully
     */
    public function transactionCommit();

    /**
     * Rollback of the current tx
     */
    public function transactionRollback();

    /**
     * Returns whether this connection uses a specific driver. In general please dont use this method
     * since it makes your code dependent on a specific driver. This is only intended for rare cases i.e.
     * to execute a migration for a specific database type
     *
     * @param string $class
     * @return bool
     */
    public function hasDriver(string $class): bool;

    /**
     * Returns all tables used by the project
     *
     * @return array
     * @throws QueryException
     */
    public function getTables();

    /**
     * Fetches extensive information per database table
     * @param $tableName
     * @return Table
     * @throws QueryException
     */
    public function getTableInformation($tableName): Table;

    /**
     * Returns the db-specific datatype for the kajona internal datatype.
     * Currently, this are
     *
     * @param string $strType
     * @return string
     * @see DataType
     */
    public function getDatatype($strType);

    /**
     * Used to send a create table statement to the database
     * By passing the query through this method, the driver can
     * add db-specific commands.
     * The array of fields should have the following structure
     * $array[string columnName] = array(string data-type, boolean isNull [, default (only if not null)])
     * whereas data-type is one of the following:
     *         int
     *      long
     *         double
     *         char10
     *         char20
     *         char100
     *         char254
     *      char500
     *         text
     *      longtext
     *
     * @param string $strName
     * @param array $arrFields array of fields / columns
     * @param array $arrKeys array of primary keys
     * @param array $arrIndices array of additional indices
     *
     * @return bool
     * @throws QueryException
     * @see DataType
     */
    public function createTable($strName, $arrFields, $arrKeys, $arrIndices = array());

    /**
     * Drops a table from the database. Checks also whether the table already exists
     *
     * @param string $tableName
     * @throws QueryException
     */
    public function dropTable(string $tableName): void;

    /**
     * Generates a tables as configured by the passed Table definition. Includes all metadata such as
     * primary keys, indexes and columns.
     * @param Table $table
     * @throws QueryException
     */
    public function generateTableFromMetadata(Table $table): void;

    /**
     * Creates a new index on the provided table over the given columns. If unique is true we create a unique index
     * where each index can only occur once in the table
     *
     * @param string $strTable
     * @param string $strName
     * @param array $arrColumns
     * @param bool $bitUnique
     * @return bool
     * @throws QueryException
     */
    public function createIndex($strTable, $strName, array $arrColumns, $bitUnique = false);

    /**
     * Removes an index from the database / table
     * @param string $table
     * @param string $index
     * @return bool
     * @throws QueryException
     */
    public function deleteIndex(string $table, string $index): bool;

    /**
     * Adds an index to a table based on the import / export format
     * @param string $table
     * @param TableIndex $index
     * @return bool
     * @throws QueryException
     * @internal
     */
    public function addIndex(string $table, TableIndex $index);

    /**
     * Checks whether the table has an index with the provided name
     *
     * @param string $strTable
     * @param string $strName
     * @return bool
     * @throws QueryException
     */
    public function hasIndex($strTable, $strName): bool;

    /**
     * Renames a table
     *
     * @param $strOldName
     * @param $strNewName
     * @return bool
     * @throws QueryException
     */
    public function renameTable($strOldName, $strNewName);

    /**
     * Changes a single column, e.g. the datatype. Note in case you only change the column type you should be aware that
     * not all database engines support changing the type freely. Most engines disallow changing the type in case you
     * would loose data i.e. on oracle it is not possible to change from longtext to char(10) since then the db engine
     * would may need to truncate some rows
     *
     * @param $strTable
     * @param $strOldColumnName
     * @param $strNewColumnName
     * @param $strNewDatatype
     * @return bool
     * @throws ChangeColumnException
     */
    public function changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype);

    /**
     * Adds a column to a table
     *
     * @param $strTable
     * @param $strColumn
     * @param $strDatatype
     * @param null $bitNull
     * @param null $strDefault
     * @return bool
     * @throws AddColumnException
     */
    public function addColumn($strTable, $strColumn, $strDatatype, $bitNull = null, $strDefault = null);

    /**
     * Removes a column from a table
     *
     * @param $strTable
     * @param $strColumn
     * @return bool
     * @throws RemoveColumnException
     */
    public function removeColumn($strTable, $strColumn);

    /**
     * Checks whether a table has a specific column
     *
     * @param string $strTable
     * @param string $strColumn
     * @return bool
     * @throws QueryException
     */
    public function hasColumn($strTable, $strColumn);

    /**
     * Checks whether the provided table exists
     *
     * @param string $strTable
     * @return bool
     * @throws QueryException
     */
    public function hasTable($strTable);

    /**
     * Allows the db-driver to add database-specific surroundings to column-names.
     * E.g. needed by the mysql-drivers
     *
     * @param string $strColumn
     * @return string
     */
    public function encloseColumnName($strColumn);

    /**
     * Allows the db-driver to add database-specific surroundings to table-names.
     * E.g. needed by the mysql-drivers
     *
     * @param string $strTable
     * @return string
     */
    public function encloseTableName($strTable);

    /**
     * Helper to replace all param-placeholder with the matching value, only to be used
     * to render a debuggable-statement.
     *
     * @param $strQuery
     * @param $arrParams
     * @return string
     */
    public function prettifyQuery($strQuery, $arrParams);

    /**
     * Appends a limit expression to the provided query
     *
     * @param string $strQuery
     * @param int $intStart
     * @param int $intEnd
     * @return string
     */
    public function appendLimitExpression($strQuery, $intStart, $intEnd);

    /**
     * @param array $parts
     * @return string
     */
    public function getConcatExpression(array $parts);

    /**
     * @param array $parts
     * @return string
     */
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
     * Method which converts a PHP value to a value which can be inserted into a table. I.e. it truncates the value to
     * the fitting length for the provided datatype
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    public function convertToDatabaseValue($value, string $type);
}
