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
interface ReadConnectionInterface
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
     * @param array $arrEscapes
     * @return array
     * @throws QueryException
     * @deprecated - please use fetchAll
     */
    public function getPArray($strQuery, $arrParams = [], $intStart = null, $intEnd = null, $bitCache = true, array $arrEscapes = []);

    /**
     * Returns one row from a result-set.
     * Makes use of prepared statements.
     *
     * @param string $strQuery
     * @param array $arrParams
     * @param int $intNr
     * @param bool $bitCache
     * @param array $arrEscapes
     * @return array
     * @throws QueryException
     * @deprecated - please use fetchAssoc
     */
    public function getPRow($strQuery, $arrParams = [], $intNr = 0, $bitCache = true, array $arrEscapes = []);

    /**
     * Retrieves a single row of the referenced table, returning the requested columns and filtering by the given identifier(s).
     *
     * @param string $tableName the table name from which to select the row
     * @param array $columns a flat list of column names to select
     * @param array $identifiers mapping of column name to value to search for (e.g. ["id" => 1])
     * @param bool $cached whether a previously selected result can be reused
     * @param array|null $escapes which parameters to escape (described in {@see dbsafeParams})
     * @return array|null
     * @throws QueryException
     */
    public function selectRow(string $tableName, array $columns, array $identifiers, bool $cached = true, ?array $escapes = []): ?array;

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
}
