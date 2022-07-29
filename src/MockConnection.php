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

    public function getPArray($strQuery, $arrParams = [], $intStart = null, $intEnd = null, $bitCache = true, array $arrEscapes = []): array
    {
        return $this->rows;
    }

    public function getPRow($strQuery, $arrParams = [], $intNr = 0, $bitCache = true, array $arrEscapes = []): array
    {
        return current($this->rows);
    }

    public function selectRow(string $tableName, array $columns, array $identifiers, bool $cached = true, ?array $escapes = []): ?array
    {
        return current($this->rows);
    }

    public function getGenerator($query, array $params = [], $chunkSize = 2048, $paging = true)
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

    public function fetchFirstColumn(string $query, array $params = []): mixed
    {
        return null;
    }

    public function iterateAssociative(string $query, array $params = []): \Generator
    {
        foreach ($this->rows as $row) {
            yield $row;
        }
    }

    public function iterateColumn(string $query, array $params = []): \Generator
    {
        foreach ($this->rows as $row) {
            yield reset($row);
        }
    }

    public function _pQuery($strQuery, $arrParams = [], array $arrEscapes = []): bool
    {
        return true;
    }

    public function executeStatement(string $query, array $params = []): int
    {
        return 1;
    }

    public function getIntAffectedRows(): int
    {
        return 1;
    }

    public function insert(string $tableName, array $values, ?array $escapes = null): int
    {
        return 1;
    }

    public function multiInsert(string $strTable, array $arrColumns, array $arrValueSets, ?array $arrEscapes = null): bool
    {
        return true;
    }

    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns): bool
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

    public function getBitConnected(): bool
    {
        return true;
    }

    public function transactionBegin(): void
    {
    }

    public function transactionCommit(): void
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

    public function getDatatype($strType): string
    {
        return DataType::STR_TYPE_TEXT;
    }

    public function createTable($strName, $arrFields, $arrKeys, $arrIndices = array())
    {
        return true;
    }

    public function dropTable(string $tableName): void
    {
    }

    public function generateTableFromMetadata(Table $table): void
    {
    }

    public function createIndex($strTable, $strName, array $arrColumns, $bitUnique = false)
    {
        return true;
    }

    public function deleteIndex(string $table, string $index): bool
    {
        return true;
    }

    public function addIndex(string $table, TableIndex $index)
    {
        return true;
    }

    public function hasIndex($strTable, $strName): bool
    {
        return true;
    }

    public function renameTable($strOldName, $strNewName)
    {
        return true;
    }

    public function changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype)
    {
        return true;
    }

    public function addColumn($strTable, $strColumn, $strDatatype, $bitNull = null, $strDefault = null)
    {
        return true;
    }

    public function removeColumn($strTable, $strColumn)
    {
        return true;
    }

    public function hasColumn($strTable, $strColumn): bool
    {
        return true;
    }

    public function hasTable($strTable): bool
    {
        return true;
    }

    public function encloseColumnName($strColumn): string
    {
        return $strColumn;
    }

    public function encloseTableName($strTable): string
    {
        return $strTable;
    }

    public function prettifyQuery($strQuery, $arrParams): string
    {
        foreach ($arrParams as $strParam) {
            $strQuery = preg_replace('/\?/', isset($strParam) ? '"' . $strParam . '"' : 'NULL', $strQuery, 1);
        }

        return $strQuery;
    }

    public function appendLimitExpression($strQuery, $intStart, $intEnd): string
    {
        return $strQuery . ' LIMIT ' . $intStart . ',' . ($intEnd - $intStart + 1);
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

    public function convertToDatabaseValue($value, string $type): string
    {
        return (string) $value;
    }
}
