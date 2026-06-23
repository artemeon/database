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
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception\NoIdentityValue;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;

/**
 * DBAL driver connection that delegates to an Artemeon Connection.
 *
 * Platform selection happens in the {@see Driver} subclasses; this class only
 * wires DBAL's per-query primitives to the matching Artemeon Connection calls.
 * The server-version string is supplied by the Driver and used by DBAL solely
 * to pick a platform variant (MySQL 8.4 vs 8.0, MariaDB 11.7 vs 10.6, etc.).
 */
final class Connection implements DriverConnection
{
    public function __construct(
        private readonly ArtemeonConnection $connection,
        private readonly string $serverVersion,
    ) {
    }

    public function prepare(string $sql): DriverStatement
    {
        return new Statement($this->connection, $sql);
    }

    public function query(string $sql): DriverResult
    {
        return $this->prepare($sql)->execute();
    }

    public function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    public function exec(string $sql): int
    {
        return $this->connection->executeStatement($sql);
    }

    public function lastInsertId(): int | string
    {
        throw NoIdentityValue::new();
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function getNativeConnection(): object
    {
        return $this->connection;
    }

    public function getServerVersion(): string
    {
        return $this->serverVersion;
    }
}
