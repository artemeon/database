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

namespace Artemeon\Database\Driver;

use Artemeon\Database\ConnectionInterface;
use Artemeon\Database\DriverInterface;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\TableIndex;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Base class for all database-drivers, holds methods to be used by all drivers.
 *
 * @author sidler@mulchprod.de
 */
abstract class DriverAbstract implements DriverInterface
{
    protected array $statementsCache = [];

    protected int $affectedRowsCount = 0;


    /**
     * Detects if the current installation runs on Windows or UNIX.
     */
    protected function isWinOs(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * @inheritDoc
     */
    public function handlesDumpCompression(): bool
    {
        return !$this->isWinOs();
    }

    /**
     * @inheritDoc
     */
    public function hasColumn(string $tableName, string $columnName): bool
    {
        $table = $this->getTableInformation($tableName);
        return in_array(strtolower($columnName), $table->getColumnNames(), true);
    }

    /**
     * @inheritDoc
     */
    public function renameTable(string $oldName, string $newName): bool
    {
        $enclosedOldName = $this->encloseTableName($oldName);
        $enclosedNewName = $this->encloseTableName($newName);
        return $this->_pQuery("ALTER TABLE $enclosedOldName RENAME TO $enclosedNewName", []);
    }

    /**
     * @inheritDoc
     */
    public function changeColumn(string $table, string $oldColumnName, string $newColumnName, DataType $newDataType): bool
    {
        $enclosedTableName = $this->encloseTableName($table);
        $enclosedOldColumnName = $this->encloseColumnName($oldColumnName);
        $enclosedNewColumnName = $this->encloseColumnName($newColumnName);
        $dataType = $this->getDatatype($newDataType);

        return $this->_pQuery("ALTER TABLE $enclosedTableName CHANGE COLUMN $enclosedOldColumnName $enclosedNewColumnName $dataType", []);
    }

    /**
     * @inheritDoc
     */
    public function addColumn(string $table, string $column, DataType $dataType, bool $nullable = null, string $default = null): bool
    {
        $enclosedTableName = $this->encloseTableName($table);
        $enclosedColumnName = $this->encloseColumnName($column);
        $mappedDataType = $this->getDatatype($dataType);

        $query = "ALTER TABLE $enclosedTableName ADD $enclosedColumnName $mappedDataType";

        if ($nullable !== null) {
            $query .= $nullable ? ' NULL' : ' NOT NULL';
        }

        if ($default !== null) {
            $query .= ' DEFAULT ' . $default;
        }

        return $this->_pQuery($query, []);
    }

    /**
     * @inheritdoc
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false): bool
    {
        return $this->_pQuery(
            'CREATE' . ($unique ? ' UNIQUE' : '') . " INDEX $name ON $table (" . implode(',', $columns) . ')',
            [],
        );
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $table, string $index): bool
    {
        return $this->_pQuery("DROP INDEX $index", []);
    }

    /**
     * @inheritDoc
     */
    public function addIndex(string $table, TableIndex $index): bool
    {
        return $this->createIndex($table, $index->getName(), explode(',', $index->getDescription()));
    }

    /**
     * @inheritDoc
     */
    public function removeColumn(string $table, string $column): bool
    {
        $enclosedTableName = $this->encloseTableName($table);
        $enclosedColumnName = $this->encloseColumnName($column);

        return $this->_pQuery("ALTER TABLE $enclosedTableName DROP COLUMN $enclosedColumnName", []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function triggerMultiInsert(string $table, array $columns, array $valueSets, ConnectionInterface $database, ?array $escapes): bool
    {
        $safeColumns = array_map(function ($column) { return $this->encloseColumnName($column); }, $columns);
        $paramsPlaceholder = '(' . implode(',', array_fill(0, count($safeColumns), '?')) . ')';
        $placeholderSets = [];
        $params = [];
        $escapeValues = [];
        foreach ($valueSets as $singleSet) {
            $placeholderSets[] = $paramsPlaceholder;
            $params[] = array_values($singleSet);
            if ($escapes !== null) {
                $escapeValues[] = $escapes;
            }
        }
        $insertStatement = 'INSERT INTO ' . $this->encloseTableName($table) . ' (' . implode(',', $safeColumns) . ') VALUES ' . implode(',', $placeholderSets);

        return $database->_pQuery($insertStatement, array_merge(...$params), $escapeValues !== [] ? array_merge(...$escapeValues) : []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function insertOrUpdate(string $table, array $columns, array $values, array $primaryColumns): bool
    {
        $placeholders = [];
        $mappedColumns = [];

        $updateKeyValues = [];
        $updateKeyValueKeys = [];
        $updateParams = [];
        $updateKeyParams = [];

        $primaryCompares = [];
        $primaryValues = [];

        foreach ($columns as $key => $column) {
            $placeholders[] = '?';
            $mappedColumns[] = $this->encloseColumnName($column);

            if (in_array($column, $primaryColumns, true)) {
                $primaryCompares[] = "$column = ? ";
                $primaryValues[] = $values[$key];

                $updateKeyValueKeys[] = "$column = ? ";
                $updateKeyParams[] = $values[$key];
            } else {
                $updateKeyValues[] = "$column = ? ";
                $updateParams[] = $values[$key];
            }
        }

        $enclosedTableName = $this->encloseTableName($table);

        $rows = $this->getPArray("SELECT COUNT(*) AS cnt FROM $enclosedTableName WHERE " . implode(' AND ', $primaryCompares), $primaryValues)->current();

        if ($rows === false) {
            return false;
        }

        $firstRow = $rows[0] ?? null;

        if ($firstRow === null || $firstRow['cnt'] === '0') {
            $query = "INSERT INTO $enclosedTableName (" . implode(', ', $mappedColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';

            return $this->_pQuery($query, $values);
        }

        if (count($updateKeyValues) === 0) {
            return true;
        }
        $query = "UPDATE $enclosedTableName SET " . implode(', ', $updateKeyValues) . ' WHERE ' . implode(' AND ', $updateKeyValueKeys);

        return $this->_pQuery($query, array_merge($updateParams, $updateKeyParams));
    }

    /**
     * @inheritDoc
     */
    public function encloseColumnName(string $column): string
    {
        return $column;
    }

    /**
     * @inheritDoc
     */
    public function encloseTableName(string $table): string
    {
        return $table;
    }

    /**
     * @inheritDoc
     */
    public function flushQueryCache(): void
    {
        $this->statementsCache = [];
    }

    public function escape(mixed $value): mixed
    {
        return $value;
    }

    /**
     * @inheritdoc
     */
    public function getAffectedRowsCount(): int
    {
        return $this->affectedRowsCount;
    }

    /**
     * @inheritdoc
     */
    public function appendLimitExpression(string $query, int $start, int $end): string
    {
        // Calculate the end-value: mysql limit: start, nr of records, so:
        $end = $end - $start + 1;
        // Add the limits to the query

        return "$query LIMIT $start, $end";
    }

    /**
     * @inheritdoc
     */
    public function getConcatExpression(array $parts): string
    {
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }

    /**
     * @inheritDoc
     */
    public function convertToDatabaseValue(mixed $value, DataType $type): mixed
    {
        return match ($type) {
            DataType::CHAR10 => mb_substr($value, 0, 10),
            DataType::CHAR20 => mb_substr($value, 0, 20),
            DataType::CHAR100 => mb_substr($value, 0, 100),
            DataType::CHAR254 => mb_substr($value, 0, 254),
            DataType::CHAR500 => mb_substr($value, 0, 500),
            default => $value,
        };
    }

    /**
     * @inheritdoc
     */
    public function getLeastExpression(array $parts): string
    {
        return 'LEAST(' . implode(', ', $parts) . ')';
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
        return 'LENGTH('.$targetString.')';
    }

    protected function runCommand(string $command): void
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
