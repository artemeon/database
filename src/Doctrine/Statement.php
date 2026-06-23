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

namespace Artemeon\Database\Doctrine;

use Artemeon\Database\Connection as ArtemeonConnection;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\ParameterType;
use LogicException;
use Stringable;

/**
 * DBAL driver statement that delegates to an Artemeon Connection.
 *
 * Values are buffered via {@see bindValue()} and replayed in positional order at
 * {@see execute()} time. The Artemeon driver layer only accepts positional `?`
 * placeholders, so named parameters are rejected (the DBAL Connection rewrites
 * them to positional before reaching us).
 */
final class Statement implements DriverStatement
{
    /** @var array<int, bool|float|int|string|null> */
    private array $values = [];

    public function __construct(
        private readonly ArtemeonConnection $connection,
        private readonly string $sql,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        if (is_string($param)) {
            throw new LogicException(
                'Named parameters are not supported by the Artemeon DBAL adapter; use positional placeholders.',
            );
        }

        if ($type === ParameterType::BOOLEAN && is_bool($value)) {
            $value = (int) $value;
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if ($value !== null && !is_scalar($value)) {
            throw new LogicException(
                'Unsupported parameter type: ' . get_debug_type($value),
            );
        }

        $this->values[$param] = $value;
    }

    public function execute(): Result
    {
        ksort($this->values);
        $params = array_values($this->values);

        if (self::returnsResultSet($this->sql)) {
            $rows = $this->connection->fetchAllAssociative($this->sql, $params);

            return new Result($rows, count($rows));
        }

        $this->connection->_pQuery($this->sql, $params, array_fill(0, count($params), false));
        $affected = $this->connection->getAffectedRowsCount();

        return new Result([], $affected);
    }

    /**
     * Heuristic to decide whether a SQL string returns a result set.
     *
     * This exists because Artemeon splits execution into two separate methods — fetchAllAssociative()
     * for queries and _pQuery() for statements — so the driver adapter must route before DBAL decides
     * which result interface to use. A real PDO-backed driver never needs this because PDO's execute()
     * returns a unified handle that supports both fetch() and rowCount() regardless of SQL type.
     *
     * Known limitation: writable CTEs (WITH … INSERT/UPDATE/DELETE) start with WITH and are therefore
     * misclassified as queries, causing them to be routed through fetchAllAssociative() instead of
     * _pQuery(). The proper fix is to add a unified execute-and-return-result method to the Artemeon
     * Connection that returns both rows and an affected-row count in one call, eliminating the need
     * for this heuristic entirely.
     */
    private static function returnsResultSet(string $sql): bool
    {
        $head = strtoupper(ltrim($sql, " \t\r\n("));
        foreach (['SELECT', 'WITH', 'SHOW', 'PRAGMA', 'EXPLAIN', 'DESCRIBE', 'VALUES', 'TABLE'] as $keyword) {
            if (str_starts_with($head, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
