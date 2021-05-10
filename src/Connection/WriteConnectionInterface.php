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

use Artemeon\Database\Exception\QueryException;

/**
 * @since 7.3
 */
interface WriteConnectionInterface
{
    /**
     * Sending a prepared statement to the database
     *
     * @param string $strQuery
     * @param array $arrParams
     * @param array $arrEscapes An array of booleans for each param, used to block the escaping of html-special chars.
     *                          If not passed, all params will be cleaned.
     * @return bool
     * @throws QueryException
     * @since 3.4
     */
    public function _pQuery($strQuery, $arrParams = [], array $arrEscapes = []);

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
     * @param array $escapes
     * @return bool
     * @throws QueryException
     */
    public function insert(string $tableName, array $values, ?array $escapes = null);

    /**
     * Creates a single query in order to insert multiple rows at one time.
     * For most databases, this will create s.th. like
     * INSERT INTO $strTable ($arrColumns) VALUES (?, ?), (?, ?)...
     *
     * @param string $strTable
     * @param string[] $arrColumns
     * @param array $arrValueSets
     * @param array|null $arrEscapes
     * @return bool
     * @throws QueryException
     */
    public function multiInsert(string $strTable, array $arrColumns, array $arrValueSets, ?array $arrEscapes = null);

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
     * @param array|null $escapes
     * @return bool
     * @throws QueryException
     */
    public function update(string $tableName, array $values, array $identifier, ?array $escapes = null): bool;

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
}
