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

use Artemeon\Database\ConnectionParameters;
use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\Table;
use Artemeon\Database\Schema\TableColumn;
use Artemeon\Database\Schema\TableIndex;
use Artemeon\Database\Schema\TableKey;
use Generator;

class SqlsrvDriver extends DriverAbstract
{
    /**
     * @var ?resource
     */
    private $linkDB;

    /**
     * @inheritdoc
     */
    public function dbconnect(ConnectionParameters $params): bool
    {
        // We need to set this to 0 otherwise i.e. the sp_rename procedure returns false with a warning even if the
        // query was successful.
        sqlsrv_configure('WarningsReturnAsErrors', 0);

        $this->linkDB = sqlsrv_connect($params->getHost(), [
            'UID' => $params->getUsername(),
            'PWD' => $params->getPassword(),
            'Database' => $params->getDatabase(),
            'CharacterSet' => 'UTF-8',
            'ConnectionPooling' => '1',
            'MultipleActiveResultSets' => '1',
            'APP' => 'Artemeon Core',
            'TransactionIsolation' => SQLSRV_TXN_READ_UNCOMMITTED
        ]);

        if ($this->linkDB === false) {
            throw new ConnectionException('Error connecting to database: ' . $this->getError());
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function dbclose(): void
    {
        // Do n.th. to keep the persistent connection
        // sqlsrv_close($this->linkDB);
    }

    /**
     * An internal helper to convert PHP values to database values
     * currently casting them to strings, otherwise the sqlsrv driver fails to
     * set them back due to type conversions.
     */
    private function convertParamsArray(array $params): array
    {
        $converted = [];
        foreach ($params as $val) {
            // $converted[] = [$val, null, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR)]; //TODO: would be better but not working, casting internally to return type string
            $converted[] = $val === null ? null : $val . '';
        }

        return $converted;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function _pQuery(string $query, array $params): bool
    {
        $convertParamsArray = $this->convertParamsArray($params);
        $statement = sqlsrv_prepare($this->linkDB, $query, $convertParamsArray);
        if ($statement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }


        $result = sqlsrv_execute($statement);
        if (!$result) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        $this->affectedRowsCount = sqlsrv_rows_affected($statement);

        sqlsrv_free_stmt($statement);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getPArray($query, $params): Generator
    {
        $statement = sqlsrv_query($this->linkDB, $query, $this->convertParamsArray($params));
        if ($statement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $row = $this->parseResultRow($row);
            yield $row;
        }

        sqlsrv_free_stmt($statement);
    }

    /**
     * @inheritDoc
     */
    public function getError(): string
    {
        $errors = sqlsrv_errors();

        return print_r($errors, true);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function getTables(): array
    {
        $generator = $this->getPArray("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE'", []) ?? [];
        $result = [];
        foreach ($generator as $row) {
            $result[] = ['name' => strtolower($row['table_name'])];
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
        $columnInfo = $this->getPArray('SELECT * FROM information_schema.columns WHERE table_name = ?', [$tableName]) ?: [];
        foreach ($columnInfo as $column) {
            $table->addColumn(
                TableColumn::make($column['column_name'])
                    ->setInternalType($this->getCoreTypeForDbType($column))
                    ->setDatabaseType($this->getDatatype($this->getCoreTypeForDbType($column)))
                    ->setNullable($column['is_nullable'] === 'YES'),
            );
        }

        //fetch all indexes
        $indexes = $this->getPArray(
            'SELECT
                       t.name as tablename,
                       ind.name as indexname,
                       col.name as colname
                FROM
                     sys.indexes ind
                       INNER JOIN
                         sys.index_columns ic ON ind.object_id = ic.object_id and ind.index_id = ic.index_id
                       INNER JOIN
                         sys.columns col ON ic.object_id = col.object_id and ic.column_id = col.column_id
                       INNER JOIN
                         sys.tables t ON ind.object_id = t.object_id
                WHERE
                    ind.is_primary_key = 0
                  AND ind.is_unique = 0
                  AND ind.is_unique_constraint = 0
                  AND t.is_ms_shipped = 0
                  AND t.name = ?
                ORDER BY
                         t.name, ind.name, ind.index_id, ic.index_column_id;', [$tableName]) ?: [];
        $indexAggr = [];
        foreach ($indexes as $indexInfo) {
            $indexAggr[$indexInfo['indexname']] = $indexAggr[$indexInfo['indexname']] ?? [];
            $indexAggr[$indexInfo['indexname']][] = $indexInfo['colname'];
        }
        foreach ($indexAggr as $key => $desc) {
            $index = new TableIndex($key);
            $index->setDescription(implode(', ', $desc));
            $table->addIndex($index);
        }

        // fetch all keys
        $keys = $this->getPArray("SELECT Col.Column_Name 
            from INFORMATION_SCHEMA.TABLE_CONSTRAINTS Tab, INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE Col 
            WHERE Col.Constraint_Name = Tab.Constraint_Name 
              AND Col.Table_Name = Tab.Table_Name 
              AND Constraint_Type = 'PRIMARY KEY' 
              AND Col.Table_Name = ?", [$tableName]) ?: [];
        foreach ($keys as $keyInfo) {
            $key = new TableKey($keyInfo['column_name']);
            $table->addPrimaryKey($key);
        }

        return $table;
    }

    /**
     * Tries to convert a column provided by the database back to the Kajona internal type constant.
     */
    private function getCoreTypeForDbType(array $infoSchemaRow): ?DataType
    {
        if ($infoSchemaRow['data_type'] === 'int') {
            return DataType::INT;
        }

        if ($infoSchemaRow['data_type'] === 'bigint') {
            return DataType::BIGINT;
        }

        if ($infoSchemaRow['data_type'] === 'real') {
            return DataType::FLOAT;
        }

        if ($infoSchemaRow['data_type'] === 'varchar') {
            if ($infoSchemaRow['character_maximum_length'] == '10') {
                return DataType::CHAR10;
            } elseif ($infoSchemaRow['character_maximum_length'] == '20') {
                return DataType::CHAR20;
            } elseif ($infoSchemaRow['character_maximum_length'] == '100') {
                return DataType::CHAR100;
            } elseif ($infoSchemaRow['character_maximum_length'] == '254') {
                return DataType::CHAR254;
            } elseif ($infoSchemaRow['character_maximum_length'] == '500') {
                return DataType::CHAR500;
            } elseif ($infoSchemaRow['character_maximum_length'] == '-1') {
                return DataType::TEXT;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getDatatype(DataType $type): string
    {
        return match ($type) {
            DataType::INT => ' INT ',
            DataType::BIGINT => ' BIGINT ',
            DataType::FLOAT => ' FLOAT( 24 ) ',
            DataType::CHAR10 => ' VARCHAR( 10 ) ',
            DataType::CHAR20 => ' VARCHAR( 20 ) ',
            DataType::CHAR100 => ' VARCHAR( 100 ) ',
            DataType::CHAR500 => ' VARCHAR( 500 ) ',
            DataType::TEXT, DataType::LONGTEXT => ' VARCHAR( MAX ) ',
            default => ' VARCHAR( 254 ) ',
        };
    }

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function renameTable(string $oldName, string $newName): bool
    {
        $enclosedOldName = $this->encloseTableName($oldName);
        $enclosedNewName = $this->encloseTableName($newName);

        return $this->_pQuery("EXEC sp_rename $enclosedOldName, $enclosedNewName", []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function changeColumn(string $table, string $oldColumnName, string $newColumnName, DataType $newDataType): bool
    {
        if ($oldColumnName !== $newColumnName) {
            $output = $this->_pQuery("EXEC sp_rename '$table.$oldColumnName', '$newColumnName', 'COLUMN'", []);
        } else {
            $output = true;
        }

        return $output && $this->_pQuery("ALTER TABLE $table ALTER COLUMN $newColumnName {$this->getDatatype($newDataType)}", []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function addColumn(string $table, string $column, DataType $dataType, bool $nullable = null, string $default = null): bool
    {
        $enclosedTableName = $this->encloseTableName($table);
        $enclosedColumnName = $this->encloseColumnName($column);

        $query = "ALTER TABLE $enclosedTableName ADD $enclosedColumnName " . $this->getDatatype($dataType);

        if ($default !== null) {
            $query .= " DEFAULT $default";
        }

        if ($nullable !== null) {
            $query .= $nullable ? ' NULL' : ' NOT NULL';
        }

        return $this->_pQuery($query, []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function createTable(string $name, array $columns, array $primaryKeys): bool
    {
        $query = '';

        // loop over existing tables to check, if the table already exists.
        $tables = $this->getTables();
        foreach ($tables as $table) {
            if ($table['name'] === $name) {
                return true;
            }
        }

        $enclosedTableName = $this->encloseTableName($name);

        // build the oracle code
        $query .= "CREATE TABLE $enclosedTableName ( \n";

        // loop the fields
        foreach ($columns as $columnName => $columnSettings) {
            $enclosedColumnName = $this->encloseColumnName($columnName);

            $query .= " $enclosedColumnName ";

            $query .= $this->getDatatype($columnSettings[0]);

            // any default?
            if (isset($columnSettings[2])) {
                $query .= 'DEFAULT ' . $columnSettings[2] . ' ';
            }

            // nullable?
            if ($columnSettings[1] === true) {
                $query .= ' NULL ';
            } else {
                $query .= ' NOT NULL ';
            }

            $query .= " , \n";
        }

        // primary keys
        $query .= ' CONSTRAINT pk_' .uniqid() . ' primary key ( ' . implode(' , ', $primaryKeys)." ) \n";
        $query .= ') ';

        return $this->_pQuery($query, []);
    }

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false): bool
    {
        return $this->_pQuery('CREATE' . ($unique ? ' UNIQUE' : '') . " INDEX $name ON $table (" . implode(',', $columns) . ')', []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function deleteIndex(string $table, string $index): bool
    {
        return $this->_pQuery("DROP INDEX $table.$index", []);
    }

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function hasIndex($table, $name): bool
    {
        $query = 'SELECT name FROM sys.indexes WHERE name = ? AND object_id = OBJECT_ID(?)';

        $index = iterator_to_array($this->getPArray($query, [$name, $table]), false);

        return count($index) > 0;
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate(string $table, array $columns, array $values, array $primaryColumns): bool
    {
        $placeholders = [];
        $mappedColumns = [];
        $keyValuePairs = [];
        $params = [];
        $primaryCompares = [];

        foreach ($columns as $key => $column) {
            $enclosedColumnName = $this->encloseColumnName($column);

            $placeholders[] = '?';
            $mappedColumns[] = $enclosedColumnName;
            $keyValuePairs[] = $enclosedColumnName . ' = ?';

            if (in_array($column, $primaryColumns, true)) {
                $primaryCompares[] = $column . ' = ? ';
                $params[] = $values[$key];
            }
        }

        $params = array_merge($params, $values, $values, $params);

        $enclosedTableName = $this->encloseTableName($table);

        $query = '
            IF NOT EXISTS (SELECT ' . implode(',', $primaryColumns) . " FROM $enclosedTableName WHERE " . implode(
                ' AND ', $primaryCompares) . ")
                INSERT INTO $enclosedTableName (" . implode(', ', $mappedColumns) . ') 
                     VALUES (' . implode(', ', $placeholders) . ")
            ELSE
                UPDATE $enclosedTableName SET " . implode(', ', $keyValuePairs) . '
                 WHERE ' . implode(' AND ', $primaryCompares);

        return $this->_pQuery($query, $params);
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        sqlsrv_begin_transaction($this->linkDB);
    }

    /**
     * @inheritDoc
     */
    public function transactionBegin(): void
    {
        $this->beginTransaction();
    }

    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        sqlsrv_commit($this->linkDB);
    }

    /**
     * @inheritDoc
     */
    public function transactionCommit(): void
    {
        $this->commit();
    }

    /**
     * @inheritDoc
     */
    public function rollBack(): void
    {
        sqlsrv_rollback($this->linkDB);
    }

    /**
     * @inheritDoc
     */
    public function transactionRollback(): void
    {
        $this->rollBack();
    }

    /**
     * @inheritDoc
     */
    public function getDbInfo(): array
    {
        return sqlsrv_server_info($this->linkDB);
    }

    public function getStringLengthExpression(string $targetString): string
    {
        return 'LEN(' . $targetString . ')';
    }

    //--- DUMP & RESTORE ------------------------------------------------------------------------------------

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
    public function dbExport(&$fileName, $tables): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function dbImport($fileName): bool
    {
        return false;
    }

    /**
     * Converts a result-row. Changes all keys to lower-case keys again.
     */
    private function parseResultRow(array $row): array
    {
        $row = array_change_key_case($row);
        if (isset($row['count(*)'])) {
            $row['COUNT(*)'] = $row['count(*)'];
        }

        return $row;
    }

    /**
     * @inheritdoc
     */
    public function appendLimitExpression(string $query, int $start, int $end): string
    {
        $length = $end - $start + 1;

        // OFFSET and FETCH can only be used with an ORDER BY.
        if (!$this->containsOrderBy($query)) {
            // Should be fixed but produces a file write on every call, so it is bad for the performance.
            // Logger::getInstance(Logger::DBLOG)->warning("Using a limit expression without an order by: {$query}");

            $query .= ' ORDER BY 1 ASC ';
        }

        return $query . " OFFSET $start ROWS FETCH NEXT $length ROWS ONLY";
    }

    /**
     * @inheritdoc
     */
    public function getConcatExpression(array $parts): string
    {
        return '(' . implode(' + ', $parts) . ')';
    }

    private function containsOrderBy(string $query): bool
    {
        $pos = stripos($query, 'ORDER BY');
        if ($pos === false) {
            return false;
        }

        // here is now the most fucked up heuristic to detect whether we have an ORDER BY in the outer query and
        // not in a sub query.
        $lastPos = strrpos($query, ')');

        if ($lastPos !== false) {
            // in case the order by is after the closing brace we have an order by otherwise it is used in a sub
            // query.
            return $pos > $lastPos;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function encloseColumnName(string $column): string
    {
        return '[' . $column . ']';
    }

    /**
     * @inheritDoc
     */
    public function encloseTableName($table): string
    {
        return '[' . $table . ']';
    }

    /**
     * @inheritdoc
     */
    public function getLeastExpression(array $parts): string
    {
        return '(SELECT MIN(x) FROM (VALUES (' . implode('),(', $parts) . ')) AS value(x))';
    }

    public function getSubstringExpression(string $value, int $offset, ?int $length): string
    {
        $parameters = [$value, $offset];
        if (isset($length)) {
            $parameters[] = $length;
        } else {
            $parameters[] = 'LEN(' . $value . ') - ' . ($offset - 1);
        }

        return 'SUBSTRING(' . implode(', ', $parameters) . ')';
    }
}
