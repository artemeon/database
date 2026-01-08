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

use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\Table;
use Artemeon\Database\Schema\TableIndex;
use Generator;
use Override;

use function current;

/**
 * Minimalistic in-memory replacement of the AGP database for tests.
 *
 * Allows to add (and clear) rows that will be unconditionally selected by the query methods. Every other method is a
 * no-op and will return sensible default values where possible.
 *
 * Example usage in a unit test (after installation of a TestDatabase instance in the dependency injection container):
 *
 *     $messageSystemId = generateSystemid();
 *     $messagingMessage = $this->prophesize(MessagingMessage::class);
 *     $messagingMessage->getSystemid()
 *         ->willReturn($messageSystemId);
 *     // additional mock object setup code
 *     Objectfactory::getInstance()->addObjectToCache($messageSystemId);
 *     $testDatabase->addRow(['system_id' => $messageSystemId, 'system_class' => MessagingMessage::class]);
 *     // invoke e.g. MessagingMessage::getObjectListFiltered() that directly acts on the database
 *
 * @since 8.0
 */
class MockConnection implements ConnectionInterface
{
    /**
     * @var list<array<array-key, mixed>>
     */
    private array $rows = [];

    /**
     * Adds a row to unconditionally be returned from {@see getPArray()} and {@see getGenerator()}. It will also be
     * returned from {@see getPRow()} and {@see selectRow()} _if it's the first row added_.
     *
     * @param array<array-key, mixed> $row
     */
    public function addRow(array $row): void
    {
        $this->rows[] = $row;
    }

    /**
     * Clears the rows returned from {@see getPArray()}, {@see getGenerator()}, {@see getPRow()} and {@see selectRow()}.
     */
    public function clearRows(): void
    {
        $this->rows = [];
    }

    #[Override]
    public function getPArray(string $query, array $params = [], ?int $start = null, ?int $end = null, bool $cache = true, array $escapes = []): array
    {
        return $this->rows;
    }

    #[Override]
    public function getPRow(string $query, array $params = [], int $number = 0, bool $cache = true, array $escapes = []): array
    {
        return current($this->rows);
    }

    #[Override]
    public function selectRow(string $tableName, array $columns, array $identifiers, bool $cached = true, ?array $escapes = []): ?array
    {
        return current($this->rows);
    }

    #[Override]
    public function getGenerator(string $query, array $params = [], int $chunkSize = 2048, bool $paging = true): Generator
    {
        yield from $this->rows;
    }

    #[Override]
    public function fetchAllAssociative(string $query, array $params = []): array
    {
        return $this->rows;
    }

    #[Override]
    public function fetchAssociative(string $query, array $params = []): array | false
    {
        return reset($this->rows);
    }

    #[Override]
    public function fetchFirstColumn(string $query, array $params = []): array
    {
        $values = [];
        foreach ($this->rows as $row) {
            $values[] = reset($row);
        }

        return $values;
    }

    #[Override]
    public function fetchOne(string $query, array $params = []): mixed
    {
        return null;
    }

    #[Override]
    public function iterateAssociative(string $query, array $params = []): Generator
    {
        foreach ($this->rows as $row) {
            yield $row;
        }
    }

    #[Override]
    public function iterateColumn(string $query, array $params = []): Generator
    {
        foreach ($this->rows as $row) {
            yield reset($row);
        }
    }

    #[Override]
    public function _pQuery(string $query, array $params = [], array $escapes = []): bool
    {
        return true;
    }

    #[Override]
    public function executeStatement(string $query, array $params = []): int
    {
        return 1;
    }

    #[Override]
    public function getAffectedRowsCount(): int
    {
        return 1;
    }

    #[Override]
    public function insert(string $tableName, array $values, ?array $escapes = null): int
    {
        return 1;
    }

    #[Override]
    public function multiInsert(string $tableName, array $columns, array $valueSets, ?array $escapes = null): bool
    {
        return true;
    }

    #[Override]
    public function insertOrUpdate(string $tableName, array $columns, array $values, array $primaryColumns): bool
    {
        return true;
    }

    #[Override]
    public function update(string $tableName, array $values, array $identifier, ?array $escapes = null): int
    {
        return 1;
    }

    #[Override]
    public function delete(string $tableName, array $identifier): int
    {
        return 1;
    }

    #[Override]
    public function isConnected(): bool
    {
        return true;
    }

    #[Override]
    public function beginTransaction(): void
    {
    }

    #[Override]
    public function transactionBegin(): void
    {
    }

    #[Override]
    public function commit(): void
    {
    }

    #[Override]
    public function transactionCommit(): void
    {
    }

    #[Override]
    public function rollBack(): void
    {
    }

    #[Override]
    public function transactionRollback(): void
    {
    }

    #[Override]
    public function hasDriver(string $class): bool
    {
        return true;
    }

    #[Override]
    public function getTables(): array
    {
        return [];
    }

    #[Override]
    public function getTableInformation(string $tableName): Table
    {
        throw new QueryException('not implemented', 'getTableInformation', []);
    }

    #[Override]
    public function getDatatype(DataType $type): string
    {
        return DataType::TEXT->value;
    }

    #[Override]
    public function createTable(string $tableName, array $columns, array $keys, array $indices = []): bool
    {
        return true;
    }

    #[Override]
    public function dropTable(string $tableName): void
    {
    }

    #[Override]
    public function generateTableFromMetadata(Table $table): void
    {
    }

    #[Override]
    public function createIndex(string $tableName, string $name, array $columns, bool $unique = false): bool
    {
        return true;
    }

    #[Override]
    public function deleteIndex(string $table, string $index): bool
    {
        return true;
    }

    #[Override]
    public function addIndex(string $table, TableIndex $index): bool
    {
        return true;
    }

    #[Override]
    public function hasIndex(string $tableName, string $name): bool
    {
        return true;
    }

    #[Override]
    public function renameTable(string $oldName, string $newName): bool
    {
        return true;
    }

    #[Override]
    public function changeColumn(string $tableName, string $oldColumnName, string $newColumnName, DataType $newDataType): bool
    {
        return true;
    }

    #[Override]
    public function addColumn(string $table, string $column, DataType $dataType, ?bool $nullable = null, ?string $default = null): bool
    {
        return true;
    }

    #[Override]
    public function removeColumn(string $tableName, string $column): bool
    {
        return true;
    }

    #[Override]
    public function hasColumn(string $tableName, string $column): bool
    {
        return true;
    }

    #[Override]
    public function hasTable(string $tableName): bool
    {
        return true;
    }

    #[Override]
    public function encloseColumnName(string $column): string
    {
        return $column;
    }

    #[Override]
    public function encloseTableName(string $tableName): string
    {
        return $tableName;
    }

    #[Override]
    public function prettifyQuery(string $query, array $params): string
    {
        foreach ($params as $param) {
            $query = (string) preg_replace('/\?/', isset($param) ? '"' . $param . '"' : 'NULL', $query, 1);
        }

        return $query;
    }

    #[Override]
    public function appendLimitExpression(string $query, int $start, int $end): string
    {
        return $query . ' LIMIT ' . $start . ',' . ($end - $start + 1);
    }

    #[Override]
    public function getConcatExpression(array $parts): string
    {
        return 'CONCAT(' . implode(',', $parts) . ')';
    }

    #[Override]
    public function getLeastExpression(array $parts): string
    {
        return 'LEAST(' . implode(',', $parts) . ')';
    }

    #[Override]
    public function getSubstringExpression(string $value, int $offset, ?int $length): string
    {
        $parameters = [$value, $offset];
        if (isset($length)) {
            $parameters[] = $length;
        }

        return 'SUBSTRING(' . implode(', ', $parameters) . ')';
    }

    #[Override]
    public function getStringLengthExpression(string $targetString): string
    {
        return 'LENGTH(' . $targetString . ')';
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, DataType $type): string
    {
        return (string) $value;
    }

    #[Override]
    public function getDbInfo(): array
    {
        return [];
    }

    #[Override]
    public function getQueries(): array
    {
        return [];
    }

    #[Override]
    public function getNumber(): int
    {
        return 0;
    }

    #[Override]
    public function getNumberCache(): int
    {
        return 0;
    }

    #[Override]
    public function getCacheSize(): int
    {
        return 0;
    }

    #[Override]
    public function getJsonColumnExpression(string $column, string $key): string
    {
        return '';
    }

    #[Override]
    public function getNthLastElementFromSlug(string $column, int $position): string
    {
        return '';
    }

    #[Override]
    public function flushQueryCache(): void
    {
    }

    #[Override]
    public function flushTablesCache(): void
    {
    }

    #[Override]
    public function flushPreparedStatementsCache(): void
    {
    }

    #[Override]
    public function getColumnsOfTable(string $tableName): array
    {
        return [];
    }

    #[Override]
    public function escape(mixed $value): mixed
    {
        return $value;
    }

    #[Override]
    public function hasOpenTransactions(): bool
    {
        return false;
    }
}
