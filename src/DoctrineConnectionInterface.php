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

/**
 * Interface which is compatible to the Doctrine DBAL Connection class {@link https://github.com/doctrine/dbal/blob/3.3.x/src/Connection.php}
 * If your service uses only those new methods it is recommended to type hint against this DoctrineConnectionInterface interface instead of the ConnectionInterface.
 */
interface DoctrineConnectionInterface
{
    /**
     * Prepares and executes an SQL query and returns the result as an array of associative arrays.
     */
    public function fetchAllAssociative(string $query, array $params = []): array;

    /**
     * Prepares and executes an SQL query and returns the first row of the result
     * as an associative array.
     */
    public function fetchAssociative(string $query, array $params = []): array|false;

    /**
     * Prepares and executes an SQL query and returns the result as an array of the first column values.
     */
    public function fetchFirstColumn(string $query, array $params = []): array;

    /**
     * Prepares and executes an SQL query and returns the value of a single column of the first row of the result.
     */
    public function fetchOne(string $query, array $params = []): mixed;

    /**
     * Prepares and executes an SQL query and returns the result as an iterator over rows represented
     * as associative arrays.
     */
    public function iterateAssociative(string $query, array $params = []): \Generator;

    /**
     * Prepares and executes an SQL query and returns the result as an iterator over the first column values.
     */
    public function iterateColumn(string $query, array $params = []): \Generator;

    /**
     * Executes an SQL statement with the given parameters and returns the number of affected rows.
     *
     * Could be used for:
     *  - DML statements: INSERT, UPDATE, DELETE, etc.
     *  - DDL statements: CREATE, DROP, ALTER, etc.
     *  - DCL statements: GRANT, REVOKE, etc.
     *  - Session control statements: ALTER SESSION, SET, DECLARE, etc.
     *  - Other statements that don't yield a row set.
     */
    public function executeStatement(string $query, array $params = []): int;

    /**
     * Creates a simple insert for a single row where the values parameter is an associative array with column names to
     * value mapping
     */
    public function insert(string $tableName, array $values, ?array $escapes = null): int;

    /**
     * Updates a row on the provided table by the identifier columns
     */
    public function update(string $tableName, array $values, array $identifier, ?array $escapes = null): int;

    /**
     * Deletes a row on the provided table by the identifier columns
     */
    public function delete(string $tableName, array $identifier): int;
}
