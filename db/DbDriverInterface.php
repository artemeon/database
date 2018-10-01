<?php
/*"******************************************************************************************************
*   (c) 2004-2006 by MulchProductions, www.mulchprod.de                                                 *
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/

namespace Kajona\System\System\Db;

use Kajona\System\System\Database;
use Kajona\System\System\Db\Schema\Table;
use Kajona\System\System\Db\Schema\TableIndex;
use Kajona\System\System\DbConnectionParams;

/**
 * Interface to specify the layout of db-drivers.
 * Implement this interface, if you want to provide a db-layer for Kajona.
 *
 * @package module_system
 */
interface DbDriverInterface
{

    /**
     * This method makes sure to connect to the database properly
     *
     * @param DbConnectionParams $objParams
     *
     * @return bool
     */
    public function dbconnect(DbConnectionParams $objParams);

    /**
     * Closes the connection to the database
     *
     * @return void
     */
    public function dbclose();

    /**
     * Creates a single query in order to insert multiple rows at one time.
     * For most databases, this will create s.th. like
     * INSERT INTO $strTable ($arrColumns) VALUES (?, ?), (?, ?)...
     * Please note that this method is used to create the query itself, based on the Kajona-internal syntax.
     * The query is fired to the database by Database
     *
     * @param string $strTable
     * @param string[] $arrColumns
     * @param array $arrValueSets
     * @param Database $objDb
     *
     * @return bool
     */
    public function triggerMultiInsert($strTable, $arrColumns, $arrValueSets, Database $objDb);

    /**
     * Fires an insert or update of a single record. it's up to the database (driver)
     * to detect whether a row is already present or not.
     * Please note: since some dbrms fire a delete && insert, make sure to pass ALL colums and values,
     * otherwise data might be lost.
     *
     * @param $strTable
     * @param $arrColumns
     * @param $arrValues
     * @param $arrPrimaryColumns
     *
     * @return bool
     * @internal param $strPrimaryColumn
     *
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns);

    /**
     * Sends a prepared statement to the database. All params must be represented by the ? char.
     * The params themselves are stored using the second params using the matching order.
     *
     * @param string $strQuery
     * @param array $arrParams
     *
     * @return bool
     * @since 3.4
     */
    public function _pQuery($strQuery, $arrParams);

    /**
     * This method is used to retrieve an array of resultsets from the database using
     * a prepared statement
     *
     * @param string $strQuery
     * @param array $arrParams
     *
     * @since 3.4
     * @return array
     */
    public function getPArray($strQuery, $arrParams);

    /**
     * Returns just a part of a recodset, defined by the start- and the end-rows,
     * defined by the params. Makes use of prepared statements.
     * <b>Note:</b> Use array-like counters, so the first row is startRow 0 whereas
     * the n-th row is the (n-1)th key!!!
     *
     * @param string $strQuery
     * @param array $arrParams
     * @param int $intStart
     * @param int $intEnd
     *
     * @return array
     * @since 3.4
     */
    public function getPArraySection($strQuery, $arrParams, $intStart, $intEnd);

    /**
     * Returns the last error reported by the database.
     * Is being called after unsuccessful queries
     *
     * @return string
     */
    public function getError();

    /**
     * Returns ALL tables in the database currently connected to.
     * The method should return an array using the following keys:
     * name => Table name
     *
     * @return array
     */
    public function getTables();

    /**
     * Fetches the full table information as retrieved from the rdbms
     * @param $tableName
     * @return Table
     */
    public function getTableInformation(string $tableName): Table;



    /**
     * Used to send a create table statement to the database
     * By passing the query through this method, the driver can
     * add db-specific commands.
     * The array of fields should have the following structure
     * $array[string columnName] = array(string datatype, boolean isNull [, default (only if not null)])
     * whereas datatype is one of the following:
     *        int
     *      long
     *        double
     *        char10
     *        char20
     *        char100
     *        char254
     *      char500
     *        text
     *      longtext
     *
     * @param string $strName
     * @param array $arrFields array of fields / columns
     * @param array $arrKeys array of primary keys
     * @param bool $bitTxSafe Should the table support transactions?
     *
     * @return bool
     */
    public function createTable($strName, $arrFields, $arrKeys, $bitTxSafe = true);

    /**
     * Creates a new index on the provided table over the given columns. If unique is true we create a unique index
     * where each index can only occur once in the table
     *
     * @param string $strTable
     * @param string $strName
     * @param array $arrColumns
     * @param bool $bitUnique
     * @return bool
     */
    public function createIndex($strTable, $strName, $arrColumns, $bitUnique = false);

    /**
     * Deletes an index from the database
     * @param string $table
     * @param string $index
     * @return bool
     */
    public function deleteIndex(string $table, string $index): bool;

    /**
     * Adds a new index to the provided table
     * @param TableIndex $index
     * @return bool
     */
    public function addIndex(string $table, TableIndex $index): bool;

    /**
     * Checks whether the table has an index with the provided name
     *
     * @param string $strTable
     * @param string $strName
     * @return bool
     */
    public function hasIndex($strTable, $strName): bool;

    /**
     * Renames a table
     *
     * @param $strOldName
     * @param $strNewName
     *
     * @return bool
     * @since 4.6
     */
    public function renameTable($strOldName, $strNewName);


    /**
     * Changes a single column, e.g. the datatype
     *
     * @param $strTable
     * @param $strOldColumnName
     * @param $strNewColumnName
     * @param $strNewDatatype
     *
     * @return bool
     * @since 4.6
     */
    public function changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype);

    /**
     * Adds a column to a table
     *
     * @param $strTable
     * @param $strColumn
     * @param $strDatatype
     *
     * @return bool
     * @since 4.6
     */
    public function addColumn($strTable, $strColumn, $strDatatype, $bitNull = null, $strDefault = null);

    /**
     * Removes a column from a table
     *
     * @param $strTable
     * @param $strColumn
     *
     * @return bool
     * @since 4.6
     */
    public function removeColumn($strTable, $strColumn);

    /**
     * Starts a transaction
     *
     * @return void
     * @since 4.6
     */
    public function transactionBegin();

    /**
     * Ends a successful operation by committing the transaction
     *
     * @return void
     * @since 4.6
     */
    public function transactionCommit();

    /**
     * Ends a non-successful transaction by using a rollback
     *
     * @return void
     */
    public function transactionRollback();

    /**
     * returns an array of key value pairs with infos about the current database
     * The array returned should have tho following structure:
     *  property name => value
     *
     * @return array
     */
    public function getDbInfo();

    /**
     * Creates an db-dump usind the given filename. the filename is relative to _realpath_
     * The dump must include, and ONLY include the pass tables
     *
     * @param string &$strFilename passed by reference so that the driver is able to update the filename, e.g. in order to add a .gz suffix
     * @param array $arrTables
     *
     * @return bool Indicates, if the dump worked or not
     */
    public function dbExport(&$strFilename, $arrTables);

    /**
     * Imports the given db-dump file to the database. The filename ist relative to _realpath_
     *
     * @param string $strFilename
     *
     * @return bool
     */
    public function dbImport($strFilename);

    /**
     * Allows the db-driver to add database-specific surroundings to column-names.
     * E.g. needed by the mysql-drivers
     *
     * @param string $strColumn
     *
     * @return string
     */
    public function encloseColumnName($strColumn);

    /**
     * Allows the db-driver to add database-specific surroundings to table-names.
     * E.g. needed by the mysql-drivers
     *
     * @param string $strTable
     *
     * @return string
     */
    public function encloseTableName($strTable);

    /**
     * Returns the db-specific datatype for the kajona internal datatype.
     *
     * @param string $strType
     *
     * @return string
     * @see DbDatatypes
     */
    public function getDatatype($strType);

    /**
     * A method triggered in special cases in order to
     * have even the caches stored at the db-driver being flushed.
     * This could get important in case of schema updates since precompiled queries may get invalid due
     * to updated table definitions.
     *
     * @return void
     */
    public function flushQueryCache();

    /**
     * @param string $strValue
     *
     * @return mixed
     */
    public function escape($strValue);

    /**
     * Appends a limit expression to the provided query. The start and end parameter are the positions of the start and
     * end row which you want include in your resultset. I.e. to return a single row use 0, 0. To return the first 8
     * rows use 0, 7.
     *
     * @param string $strQuery
     * @param int $intStart
     * @param int $intEnd
     * @return string
     */
    public function appendLimitExpression($strQuery, $intStart, $intEnd);

    /**
     * Returns a query expression which concatenates different values. This can bei either column names or strings.
     * <code>
     *  $connection->getConcatExpression(['user_kajona.user_forename', '\' \'', 'user_kajona.user_name'])
     * </code>
     *
     * @param array $parts
     * @return string
     */
    public function getConcatExpression(array $parts);

    /**
     * Returns the number of affected rows from the last _pQuery call
     *
     * @return int
     */
    public function getIntAffectedRows();


    /**
     * Default implementation to detect if a driver handles compression.
     * By default, db-drivers us a piped gzip / gunzip command when creating / restoring dumps on unix.
     * If running on windows, the Database class handles the compression / decompression.
     *
     * @return bool
     */
    public function handlesDumpCompression();
}


