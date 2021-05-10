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

namespace Artemeon\Database\Connection;

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
interface SchemaConnectionInterface
{
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
}
