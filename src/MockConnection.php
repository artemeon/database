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
    private array $rows = [];

    /**
     * Adds a row to unconditionally be returned from {@see getPArray()} and {@see getGenerator()}. It will also be
     * returned from {@see getPRow()} and {@see selectRow()} _if it's the first row added_.
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

    public function getPArray($query, $params = [], $start = null, $end = null, $cache = true, array $escapes = []): array
    {
        return $this->rows;
    }

    public function getPRow($query, $params = [], $number = 0, $cache = true, array $escapes = []): array
    {
        return current($this->rows);
    }

    public function selectRow(string $tableName, array $columns, array $identifiers, bool $cached = true, ?array $escapes = []): ?array
    {
        return current($this->rows);
    }

    public function getGenerator(string $query, array $params = [], int $chunkSize = 2048, bool $paging = true): Generator
    {
        yield from $this->rows;
    }

    public function fetchAllAssociative(string $query, array $params = []): array
    {
        return $this->rows;
    }

    public function fetchAssociative(string $query, array $params = []): array|false
    {
        return reset($this->rows);
    }

    public function fetchFirstColumn(string $query, array $params = []): array
    {
        $values = [];
        foreach ($this->rows as $row) {
            $values[] = reset($row);
        }
        return $values;
    }

    public function fetchOne(string $query, array $params = []): mixed
    {
        return null;
    }

    public function iterateAssociative(string $query, array $params = []): Generator
    {
        foreach ($this->rows as $row) {
            yield $row;
        }
    }

    public function iterateColumn(string $query, array $params = []): Generator
    {
        foreach ($this->rows as $row) {
            yield reset($row);
        }
    }

    public function _pQuery($query, $params = [], array $escapes = []): bool
    {
        return true;
    }

    public function executeStatement(string $query, array $params = []): int
    {
        return 1;
    }

    public function getAffectedRowsCount(): int
    {
        return 1;
    }

    public function insert(string $tableName, array $values, ?array $escapes = null): int
    {
        return 1;
    }

    public function multiInsert(string $tableName, array $columns, array $valueSets, ?array $escapes = null): bool
    {
        return true;
    }

    public function insertOrUpdate($tableName, $columns, $values, $primaryColumns): bool
    {
        return true;
    }

    public function update(string $tableName, array $values, array $identifier, ?array $escapes = null): int
    {
        return 1;
    }

    public function delete(string $tableName, array $identifier): int
    {
        return 1;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function beginTransaction(): void
    {
    }

    public function transactionBegin(): void
    {
    }

    public function commit(): void
    {
    }

    public function transactionCommit(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function transactionRollback(): void
    {
    }

    public function hasDriver(string $class): bool
    {
        return true;
    }

    public function getTables(): array
    {
        return [];
    }

    public function getTableInformation($tableName): Table
    {
        throw new QueryException('not implemented', 'getTableInformation', []);
    }

    public function getDatatype(DataType $type): string
    {
        return DataType::TEXT->value;
    }

    public function createTable(string $tableName, array $columns, array $keys, array $indices = []): bool
    {
        return true;
    }

    public function dropTable(string $tableName): void
    {
    }

    public function generateTableFromMetadata(Table $table): void
    {
    }

    public function createIndex(string $tableName, string $name, array $columns, bool $unique = false): bool
    {
        return true;
    }

    public function deleteIndex(string $table, string $index): bool
    {
        return true;
    }

    public function addIndex(string $table, TableIndex $index): bool
    {
        return true;
    }

    public function hasIndex($tableName, $name): bool
    {
        return true;
    }

    public function renameTable(string $oldName, string $newName): bool
    {
        return true;
    }

    public function changeColumn(string $tableName, string $oldColumnName, string $newColumnName, DataType $newDataType): bool
    {
        return true;
    }

    public function addColumn(string $table, string $column, DataType $dataType, ?bool $nullable = null, ?string $default = null): bool
    {
        return true;
    }

    public function removeColumn(string $tableName, string $column): bool
    {
        return true;
    }

    public function hasColumn(string $tableName, string $column): bool
    {
        return true;
    }

    public function hasTable($tableName): bool
    {
        return true;
    }

    public function encloseColumnName($column): string
    {
        return $column;
    }

    public function encloseTableName($tableName): string
    {
        return $tableName;
    }

    public function prettifyQuery($query, $params): string
    {
        foreach ($params as $param) {
            $query = (string) preg_replace('/\?/', isset($param) ? '"' . $param . '"' : 'NULL', $query, 1);
        }

        return $query;
    }

    public function appendLimitExpression($query, $start, $end): string
    {
        return $query . ' LIMIT ' . $start . ',' . ($end - $start + 1);
    }

    public function getConcatExpression(array $parts): string
    {
        return 'CONCAT('  . implode(',', $parts) . ')';
    }

    public function getLeastExpression(array $parts): string
    {
        return 'LEAST(' . implode(',', $parts) . ')';
    }

    public function getSubstringExpression(string $value, int $offset, ?int $length): string
    {
        $parameters = [$value, $offset];
        if (isset($length)) {
            $parameters[] = $length;
        }

        return 'SUBSTRING(' . implode(', ', $parameters) . ')';
    }

    public function getStringLengthExpression(string $targetString): string
    {
        return 'LENGTH(' . $targetString . ')';
    }

    public function convertToDatabaseValue(mixed $value, DataType $type): string
    {
        return (string) $value;
    }

    public function getDbInfo(): array
    {
        return [];
    }

    public function getQueries(): array
    {
        return [];
    }

    public function getNumber(): int
    {
        return 0;
    }

    public function getNumberCache(): int
    {
        return 0;
    }

    public function getCacheSize(): int
    {
        return 0;
    }

    public function dbExport(string &$fileName, array $tables): bool
    {
        return false;
    }

    public function dbImport(string $fileName): bool
    {
        return false;
    }
}
