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
use Artemeon\Database\ConnectionParameters;
use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\Table;
use Artemeon\Database\Schema\TableColumn;
use Artemeon\Database\Schema\TableIndex;
use Artemeon\Database\Schema\TableKey;
use Generator;
use SQLite3;
use SQLite3Stmt;
use Throwable;

/**
 * DB-driver for sqlite3 using the php-sqlite3-interface.
 * Based on the sqlite2 driver by phwolfer.
 */
class Sqlite3Driver extends DriverAbstract
{
    private ?SQLite3 $linkDB;
    private string $dbFile;

    /**
     * @inheritdoc
     */
    public function dbconnect(ConnectionParameters $params): bool
    {
        if ($params->getDatabase() === '') {
            return false;
        }

        if ($params->getDatabase() === ':memory:') {
            $this->dbFile = ':memory:';
        } else {
            $this->dbFile = $params->getAttribute(ConnectionParameters::SQLITE3_BASE_PATH) . '/' . $params->getDatabase().'.db3';
        }

        try {
            $this->linkDB = new SQLite3($this->dbFile);
            $this->_pQuery('PRAGMA encoding = "UTF-8"', []);
            $this->_pQuery('PRAGMA auto_vacuum = FULL', []);
            $this->_pQuery('PRAGMA journal_mode = WAL', []);
            $this->linkDB->busyTimeout(5000);

            return true;
        } catch (Throwable $e) {
            throw new ConnectionException('Error connecting to database', 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function dbclose(): void
    {
        if ($this->linkDB !== null) {
            $this->linkDB->close();
            $this->linkDB = null;
        }
    }

    /**
     * @throws QueryException
     */
    private function buildAndCopyTempTables(string $targetTableName, array $sourceTableInfo, array $targetTableInfo): bool
    {
        /* Get existing table info */
        $pragmaTableInfo = $this->getPArray("PRAGMA table_info('$targetTableName')", []);
        $columnsPragma = [];
        foreach ($pragmaTableInfo as $row) {
            $columnsPragma[$row['name']] = $row;
        }

        $sourceColumns = [];
        array_walk($sourceTableInfo, static function (array $value) use (&$sourceColumns) {
            $sourceColumns[] = $value['columnName'];
        });

        $targetColumns = [];
        array_walk($targetTableInfo, static function (array $value) use (&$targetColumns) {
            $targetColumns[] = $value['columnName'];
        });

        // build the temp table
        $query = 'CREATE TABLE ' . $targetTableName . "_temp ( \n";

        // loop the fields
        $columns = [];
        $pks = [];
        foreach ($targetTableInfo as $column) {
            $row = null;

            if (array_key_exists($column['columnName'], $columnsPragma)) {
                $row = $columnsPragma[$column['columnName']];
            } else {
                $row['name'] = $column['columnName'];
                $row['type'] = $column['columnType'];
            }

            // column settings
            $columnString = ' ' . $row['name'] . ' ' . $row['type'];

            if (array_key_exists('notnull', $row) && $row['notnull'] === 1) {
                $columnString .= ' NOT NULL ';
            } elseif (array_key_exists('notnull', $row) && $row['notnull'] === 0) {
                $columnString .= ' NULL ';
            }

            if (array_key_exists('dflt_value', $row) && $row['dflt_value'] !== null) {
                $columnString .= " DEFAULT {$row['dflt_value']} ";
            }
            $columns[] = $columnString;

            // primary key?
            if (array_key_exists('pk', $row) && $row['pk'] === 1) {
                $pks[] = $row['name'];
            }
        }

        // columns
        $query .= implode(",\n", $columns);

        // primary keys
        if (count($pks) > 0) {
            $query .= ',PRIMARY KEY (';
            $query .= implode(',', $pks);
            $query .= ")\n";
        }

        $query .= ")\n";

        $output = $this->_pQuery($query, []);

        //copy all values
        $query = 'INSERT INTO ' . $targetTableName . '_temp (' . implode(',', $targetColumns) . ') SELECT ' . implode(
                ',', $sourceColumns) . ' FROM ' . $targetTableName;
        $output = $output && $this->_pQuery($query, []);

        $query = 'DROP TABLE ' . $targetTableName;
        $output = $output && $this->_pQuery($query, []);

        return $output && $this->renameTable($targetTableName . '_temp', $targetTableName);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function changeColumn(string $table, string $oldColumnName, string $newColumnName, DataType $newDataType): bool
    {
        $tableDef = $this->getTableInformation($table);
        $tableInfo = [];
        $targetTableInfo = [];
        foreach ($tableDef->getColumns() as $column) {
            $newDef = [
                'columnName' => $column->getName(),
                'columnType' => $column->getInternalType(),
            ];

            $tableInfo[] = $newDef;

            if ($column->getName() === $oldColumnName) {
                $newDef = [
                    'columnName' => $newColumnName,
                    'columnType' => $this->getDatatype($newDataType),
                ];
            }

            $targetTableInfo[] = $newDef;
        }

        return $this->buildAndCopyTempTables($table, $tableInfo, $targetTableInfo);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function removeColumn(string $table, string $column): bool
    {
        $targetTableInfo = [];

        $tableDef = $this->getTableInformation($table);
        foreach ($tableDef->getColumns() as $col) {
            if ($col->getName() !== $column) {
                $targetTableInfo[] = [
                    'columnName' => $col->getName(),
                    'columnType' => $col->getInternalType(),
                ];
            }
        }

        return $this->buildAndCopyTempTables($table, $targetTableInfo, $targetTableInfo);
    }

    /**
     * @inheritDoc
     */
    public function triggerMultiInsert(string $table, array $columns, array $valueSets, ConnectionInterface $database, ?array $escapes): bool
    {
        $sqliteVersion = SQLite3::version();
        if (version_compare('3.7.11', $sqliteVersion['versionString'], '<=')) {
            return parent::triggerMultiInsert($table, $columns, $valueSets, $database, $escapes);
        }

        //legacy code
        $safeColumns = array_map(function ($column) { return $this->encloseColumnName($column); }, $columns);
        $params = [];
        $escapeValues = [];
        $insertStatement = 'INSERT INTO ' . $this->encloseTableName($table) . ' (' . implode(',', $safeColumns) . ') ';
        foreach ($valueSets as $key => $valueSet) {
            $selectStatement = $key === 0 ? ' SELECT ' : ' UNION SELECT ';
            $insertStatement .= $selectStatement . implode(', ', array_map(static function ($column) { return ' ? AS ' . $column; }, $safeColumns));
            $params[] = array_values($valueSet);
            if ($escapes !== null) {
                $escapeValues[] = $escapes;
            }
        }

        return $database->_pQuery($insertStatement, array_merge(...$params), $escapeValues !== [] ? array_merge(...$escapeValues) : []);
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate(string $table, array $columns, array $values, array $primaryColumns): bool
    {
        $placeholders = [];
        $mappedColumns = [];

        foreach ($columns as $column) {
            $placeholders[] = '?';
            $mappedColumns[] = $this->encloseColumnName($column);
        }

        $enclosedTableName = $this->encloseTableName($table);

        $query = "INSERT OR REPLACE INTO $enclosedTableName (" . implode(', ', $mappedColumns) . ') VALUES (' . implode(
                ', ', $placeholders) . ')';

        return $this->_pQuery($query, $values);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function _pQuery(string $query, array $params): bool
    {
        $query = $this->fixQuoting($query);
        $query = $this->processQuery($query);

        $statement = $this->getPreparedStatement($query);
        if ($statement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }
        $count = 1;
        foreach ($params as $param) {
            if ($param === null) {
                $statement->bindValue(':param' . $count++, $param, SQLITE3_NULL);
            } else {
                $statement->bindValue(':param' . $count++, $param);
            }
        }

        if ($statement->execute() === false) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        $this->affectedRowsCount = $this->linkDB->changes();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getPArray($query, $params): Generator
    {
        $query = $this->fixQuoting($query);
        $query = $this->processQuery($query);

        $statement = $this->getPreparedStatement($query);
        if ($statement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        $count = 1;
        foreach ($params as $param) {
            if ($param === null) {
                $statement->bindValue(':param' . $count++, $param, SQLITE3_NULL);
            } else {
                $statement->bindValue(':param' . $count++, $param);
            }
        }

        $result = $statement->execute();

        if ($result === false) {
            throw new QueryException('Could not execute statement', $query, $params);
        }

        while ($temp = $result->fetchArray(SQLITE3_ASSOC)) {
            yield $temp;
        }
    }

    /**
     * @inheritDoc
     */
    public function getError(): string
    {
        return $this->linkDB->lastErrorMsg();
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function getTables(): array
    {
        $generator = $this->getPArray("SELECT name FROM sqlite_master WHERE type='table'", []);
        $result = [];
        foreach ($generator as $row) {
            $result[] = ['name' => strtolower($row['name'])];
        }

        return $result;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function getTableInformation(string $tableName): Table
    {
        $table = new Table($tableName);

        // fetch all columns
        $columnInfo = $this->getPArray("PRAGMA table_info('$tableName')", []);
        foreach ($columnInfo as $column) {
            $table->addColumn(
                TableColumn::make($column['name'])
                    ->setInternalType($this->getCoreTypeForDbType($column))
                    ->setDatabaseType($this->getDatatype($this->getCoreTypeForDbType($column)))
                    ->setNullable($column['notnull'] == 0),
            );

            if ($column['pk'] == 1) {
                $table->addPrimaryKey(new TableKey($column['name']));
            }
        }

        // fetch all indexes
        $indexes = $this->getPArray("SELECT * FROM sqlite_master WHERE type = 'index' AND tbl_name = ?", [$tableName]);
        foreach ($indexes as $indexInfo) {
            $index = new TableIndex($indexInfo['name']);
            $index->setDescription($indexInfo['sql'] ?? '');
            $table->addIndex($index);
        }

        return $table;
    }

    /**
     * Tries to convert a column provided by the database back to the Kajona internal type constant.
     */
    private function getCoreTypeForDbType(array $infoSchemaRow): ?DataType
    {
        $val = strtolower(trim($infoSchemaRow['type']));

        if ($val === 'integer') {
            return DataType::INT;
        }

        if ($val === 'real') {
            return DataType::FLOAT;
        }

        if ($val === 'text') {
            return DataType::TEXT;
        }

        return null;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function createTable(string $name, array $columns, array $primaryKeys): bool
    {
        $query = "CREATE TABLE $name ( \n";

        // loop the fields
        foreach ($columns as $fieldName => $columnSettings) {
            $query .= " $fieldName ";

            $query .= $this->getDatatype($columnSettings[0]);

            // any default?
            if (isset($columnSettings[2])) {
                $query .= ' DEFAULT ' . $columnSettings[2] . ' ';
            }

            // nullable?
            if ($columnSettings[1] === true) {
                $query .= ", \n";
            } else {
                $query .= " NOT NULL, \n";
            }
        }

        // primary keys
        $query .= ' PRIMARY KEY (' . implode(', ', $primaryKeys) . ") \n";
        $query .= ') ';

        return $this->_pQuery($query, []);
    }

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function hasIndex($table, $name): bool
    {
        $index = iterator_to_array($this->getPArray("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?", [$table, $name]), false);
        return count($index) > 0;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function beginTransaction(): void
    {
        $this->_pQuery('BEGIN TRANSACTION', []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function transactionBegin(): void
    {
        $this->beginTransaction();
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function commit(): void
    {
        $this->_pQuery('COMMIT TRANSACTION', []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function transactionCommit(): void
    {
        $this->commit();
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function rollBack(): void
    {
        $this->_pQuery('ROLLBACK TRANSACTION', []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function transactionRollback(): void
    {
        $this->rollBack();
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function getDbInfo(): array
    {
        $timeout = iterator_to_array($this->getPArray('PRAGMA busy_timeout', []), false);
        $encoding = iterator_to_array($this->getPArray('PRAGMA encoding', []), false);

        $db = $this->linkDB->version();

        return [
            'dbserver' => 'SQLite3 ' . $db['versionString'] . ' ' . $db['versionNumber'],
            'location' => $this->dbFile,
            'busy_timeout' => $timeout[0]['timeout'] ?? '-',
            'encoding' => $encoding[0]['encoding'] ?? '-',
        ];
    }

    /**
     * @inheritdoc
     */
    public function handlesDumpCompression(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function dbExport(string &$fileName, array $tables): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function dbImport(string $fileName): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getDatatype(DataType $type): string
    {
        return match ($type) {
            DataType::INT, DataType::BIGINT => ' INTEGER ',
            DataType::FLOAT => ' REAL ',
            default => ' TEXT ',
        };
    }

    /**
     * Fixes the quoting of ' in queries.
     * By default, ' is quoted as \', but it must be quoted as '' in sqlite.
     */
    private function fixQuoting(string $query): string
    {
        return str_replace(["\\'", "\\\""], ["''", "\""], $query);
    }

    /**
     * Transforms the query into a valid sqlite-syntax.
     */
    private function processQuery(string $query): string
    {
        return preg_replace_callback('/\?/', static function (): string {
            static $i = 0;
            $i++;
            return ':param' . $i;
        }, $query);
    }

    /**
     * Prepares a statement or uses an instance from the cache.
     */
    private function getPreparedStatement(string $query): SQLite3Stmt | false
    {
        $name = md5($query);

        if (isset($this->statementsCache[$name])) {
            return $this->statementsCache[$name];
        }

        $statement = $this->linkDB->prepare($query);
        $this->statementsCache[$name] = $statement;

        return $statement;
    }

    /**
     * @inheritDoc
     */
    public function encloseTableName(string $table): string
    {
        return "'$table'";
    }

    /**
     * @inheritdoc
     */
    public function getConcatExpression(array $parts): string
    {
        return implode(' || ', $parts);
    }

    /**
     * @inheritdoc
     */
    public function getLeastExpression(array $parts): string
    {
        return 'MIN(' . implode(', ', $parts) . ')';
    }

    public function getSubstringExpression(string $value, int $offset, ?int $length): string
    {
        $parameters = [$value, $offset];
        if (isset($length)) {
            $parameters[] = $length;
        }

        return 'SUBSTR(' . implode(', ', $parameters) . ')';
    }
}
