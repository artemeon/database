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

use Artemeon\Database\Connection\PlatformConnectionInterface;
use Artemeon\Database\Connection\ReadConnectionInterface;
use Artemeon\Database\Connection\SchemaConnectionInterface;
use Artemeon\Database\Connection\WriteConnectionInterface;

/**
 * @since 7.3
 */
interface ConnectionInterface extends ReadConnectionInterface, WriteConnectionInterface, SchemaConnectionInterface, PlatformConnectionInterface
{
    /**
     * @return bool
     */
    public function getBitConnected();

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
     * Method which converts a PHP value to a value which can be inserted into a table. I.e. it truncates the value to
     * the fitting length for the provided datatype
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    public function convertToDatabaseValue($value, string $type);
}
