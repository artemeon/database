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
use Symfony\Component\Process\ExecutableFinder;

use UnexpectedValueException;

use const OCI_COMMIT_ON_SUCCESS;
use const OCI_NO_AUTO_COMMIT;

/**
 * DB-driver for Oracle using the ovi8-interface.
 */
class Oci8Driver extends DriverAbstract
{
    /** @var resource | false */
    private $linkDB;

    private ConnectionParameters $config;

    private string $dumpBin = 'exp'; // Binary to dump db (if not in path, add the path here)
    // /usr/lib/oracle/xe/app/oracle/product/10.2.0/server/bin/
    private string $restoreBin = 'imp'; // Binary to restore db (if not in path, add the path here)

    private bool $txOpen = false;

    private $errorStmt;

    /**
     * Flag whether the string comparison method (case sensitive / insensitive) should be reset back to default after the current query.
     */
    private bool $resetOrder = false;

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function dbconnect(ConnectionParameters $params): bool
    {
        $port = $params->getPort();
        if (empty($port)) {
            $port = 1521;
        }
        $this->config = $params;

        // Try to set the NLS_LANG env attribute
        putenv('NLS_LANG=American_America.UTF8');

        $this->linkDB = oci_pconnect(
            $this->config->getUsername(),
            $this->config->getPassword(),
            $this->config->getHost() . ':' . $port . '/' . $this->config->getDatabase(),
            'AL32UTF8',
        );

        if ($this->linkDB !== false) {
            oci_set_client_info($this->linkDB, 'ARTEMEON AGP');
            oci_set_client_identifier($this->linkDB, 'ARTEMEON AGP');
            $this->_pQuery("ALTER SESSION SET NLS_NUMERIC_CHARACTERS='.,'", []);
            $this->_pQuery('ALTER SESSION SET DEFAULT_COLLATION=BINARY_CI', []);

            return true;
        }

        throw new ConnectionException('Error connecting to database');
    }

    /**
     * @inheritDoc
     */
    public function dbclose(): void
    {
        // Do n.th. to keep the persistent connection
        // oci_close($this->linkDB);
    }

    /**
     * @inheritDoc
     */
    public function triggerMultiInsert(
        string $table,
        array $columns,
        array $valueSets,
        ConnectionInterface $database,
        ?array $escapes
    ): bool {
        $safeColumns = array_map(function ($column) {
            return $this->encloseColumnName($column);
        }, $columns);
        $paramsPlaceholder = '(' . implode(',', array_fill(0, count($safeColumns), '?')) . ')';
        $columnNames = ' (' . implode(',', $safeColumns) . ') ';

        $params = [];
        $escapeValues = [];
        $insertStatement = 'INSERT ALL ';
        foreach ($valueSets as $valueSet) {
            $params[] = array_values($valueSet);
            if ($escapes !== null) {
                $escapeValues[] = $escapes;
            }
            $insertStatement .= ' INTO ' . $this->encloseTableName(
                    $table
                ) . ' ' . $columnNames . ' VALUES ' . $paramsPlaceholder . ' ';
        }
        $insertStatement .= ' SELECT * FROM dual';

        return $database->_pQuery(
            $insertStatement,
            array_merge(...$params),
            $escapeValues !== [] ? array_merge(...$escapeValues) : []
        );
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function _pQuery(string $query, array $params): bool
    {
        $query = $this->processQuery($query);
        $statement = $this->getParsedStatement($query);
        if ($statement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        foreach ($params as $pos => $value) {
            if (!oci_bind_by_name($statement, ':' . ((int) $pos + 1), $params[$pos])) {
                // echo 'oci_bind_by_name failed to bind at pos >' . $pos . "<, \n value: " . $value . "\nquery: " . $query;
                return false;
            }
        }

        $addon = OCI_COMMIT_ON_SUCCESS;
        if ($this->txOpen) {
            $addon = OCI_NO_AUTO_COMMIT;
        }
        $result = oci_execute($statement, $addon);

        if (!$result) {
            $this->errorStmt = $statement;
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        $this->affectedRowsCount = oci_num_rows($statement);

        oci_free_statement($statement);

        return $result;
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
            $placeholders[] = '?';
            $mappedColumns[] = $this->encloseColumnName($column);

            if (in_array($column, $primaryColumns, true)) {
                $primaryCompares[] = "$column = ? ";
                $params[] = $values[$key];
            }
        }

        $params = array_merge($params, $values);

        foreach ($columns as $key => $column) {
            if (!in_array($column, $primaryColumns, true)) {
                $keyValuePairs[] = $this->encloseColumnName($column) . ' = ?';
                $params[] = $values[$key];
            }
        }

        $enclosedTableName = $this->encloseTableName($table);

        $query = "MERGE INTO $enclosedTableName using dual on (" . implode(' AND ', $primaryCompares) . ') 
                       WHEN NOT MATCHED THEN INSERT (' . implode(', ', $mappedColumns) . ') values (' . implode(
                ', ',
                $placeholders
            ) . ')';

        if (!empty($keyValuePairs)) {
            $query .= 'WHEN MATCHED then update set ' . implode(', ', $keyValuePairs);
        }

        return $this->_pQuery($query, $params);
    }

    /**
     * @inheritDoc
     */
    public function getPArray(string $query, array $params): Generator
    {
        $query = $this->processQuery($query);
        $statement = $this->getParsedStatement($query);

        if ($statement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        $index = 0;
        foreach ($params as $pos => $value) {
            oci_bind_by_name($statement, ':' . ++$index, $params[$pos]);
        }

        $addon = OCI_COMMIT_ON_SUCCESS;
        if ($this->txOpen) {
            $addon = OCI_NO_AUTO_COMMIT;
        }

        oci_set_prefetch($statement, 300);
        $resultSet = oci_execute($statement, $addon);

        if (!$resultSet) {
            $this->errorStmt = $statement;
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        // this was the old way, we're now no longer loading LOBS by default
        // while ($row = oci_fetch_array($objStatement, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) {
        while ($row = oci_fetch_assoc($statement)) {
            yield $this->parseResultRow($row);
        }

        oci_free_statement($statement);

        if ($this->resetOrder) {
            $this->setCaseSensitiveSort();
            $this->resetOrder = false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getError(): string
    {
        $error = oci_error($this->errorStmt ?? $this->linkDB);
        $this->errorStmt = null;

        return print_r($error, true);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function getTables(): array
    {
        $generator = $this->getPArray(
            'SELECT table_name AS name FROM ALL_TABLES WHERE owner = ?',
            [$this->config->getUsername()]
        );
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

        $tableName = strtoupper($tableName);

        // fetch all columns
        $columnInfo = $this->getPArray('SELECT * FROM user_tab_columns WHERE table_name = ?', [$tableName]);
        foreach ($columnInfo as $column) {
            $table->addColumn(
                TableColumn::make(strtolower($column['column_name']))
                    ->setInternalType($this->getCoreTypeForDbType($column))
                    ->setDatabaseType($this->getDatatype($this->getCoreTypeForDbType($column)))
                    ->setNullable($column['nullable'] === 'Y'),
            );
        }

        // fetch all indexes
        $indexes = $this->getPArray(
            '
            select b.uniqueness, a.index_name, a.table_name, a.column_name
            from all_ind_columns a, all_indexes b
            where a.index_name=b.index_name
              and a.table_name = ?
            order by a.index_name, a.column_position',
            [$tableName]
        );
        $indexAggr = [];
        foreach ($indexes as $indexInfo) {
            $indexAggr[$indexInfo['index_name']] = $indexAggr[$indexInfo['index_name']] ?? [];
            $indexAggr[$indexInfo['index_name']][] = $indexInfo['column_name'];
        }
        foreach ($indexAggr as $key => $desc) {
            $index = new TableIndex(strtolower($key));
            $index->setDescription(implode(', ', $desc));
            $table->addIndex($index);
        }

        // fetch all keys
        $keys = $this->getPArray(
            "SELECT cols.table_name, cols.column_name, cols.position, cons.status, cons.owner 
            FROM all_constraints cons, all_cons_columns cols
            WHERE cols.table_name = ?
              AND cons.constraint_type = 'P'
              AND cons.constraint_name = cols.constraint_name
              AND cons.owner = cols.owner
          ",
            [$tableName]
        );
        foreach ($keys as $keyInfo) {
            $key = new TableKey(strtolower($keyInfo['column_name']));
            $table->addPrimaryKey($key);
        }


        return $table;
    }


    /**
     * Tries to convert a column provided by the database back to the Kajona internal type constant.
     */
    private function getCoreTypeForDbType(array $infoSchemaRow): ?DataType
    {
        if ($infoSchemaRow['data_type'] === 'NUMBER' && $infoSchemaRow['data_precision'] == 19) {
            return DataType::BIGINT;
        }

        if ($infoSchemaRow['data_type'] === 'FLOAT' && $infoSchemaRow['data_precision'] == 24) {
            return DataType::FLOAT;
        }

        if ($infoSchemaRow['data_type'] === 'VARCHAR2') {
            if ($infoSchemaRow['data_length'] == '10') {
                return DataType::CHAR10;
            }

            if ($infoSchemaRow['data_length'] == '20') {
                return DataType::CHAR20;
            }

            if ($infoSchemaRow['data_length'] == '100') {
                return DataType::CHAR100;
            }

            if ($infoSchemaRow['data_length'] == '254') {
                return DataType::CHAR254;
            }

            if ($infoSchemaRow['data_length'] == '280') {
                return DataType::CHAR254;
            }

            if ($infoSchemaRow['data_length'] == '500') {
                return DataType::CHAR500;
            }

            if ($infoSchemaRow['data_length'] == '4000') {
                return DataType::TEXT;
            }

            if ($infoSchemaRow['data_length'] == '32767') {
                return DataType::TEXT;
            }
        } elseif ($infoSchemaRow['data_type'] === 'CLOB') {
            return DataType::LONGTEXT;
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getDatatype(DataType $type): string
    {
        return match ($type) {
            DataType::INT, DataType::BIGINT => ' NUMBER(19, 0) ',
            DataType::FLOAT => ' FLOAT (24) ',
            DataType::CHAR10 => ' VARCHAR2( 10 ) ',
            DataType::CHAR20 => ' VARCHAR2( 20 ) ',
            DataType::CHAR100 => ' VARCHAR2( 100 ) ',
            DataType::CHAR500 => ' VARCHAR2( 500 ) ',
            DataType::TEXT => ' VARCHAR2( 32767 ) ',
            DataType::LONGTEXT => ' CLOB ',
            default => ' VARCHAR2( 280 ) ',
        };
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function changeColumn(
        string $table,
        string $oldColumnName,
        string $newColumnName,
        DataType $newDataType,
    ): bool {
        $enclosedTableName = $this->encloseTableName($table);
        $enclosedNewColumnName = $this->encloseColumnName($newColumnName);

        if ($oldColumnName !== $newColumnName) {
            $enclosedOldColumnName = $this->encloseColumnName($oldColumnName);

            $output = $this->_pQuery(
                "ALTER TABLE $enclosedTableName RENAME COLUMN $enclosedOldColumnName TO $enclosedNewColumnName",
                [],
            );
        } else {
            $output = true;
        }

        $mappedDataType = $this->getDatatype($newDataType);

        return $output && $this->_pQuery(
                "ALTER TABLE $enclosedTableName MODIFY ( $enclosedNewColumnName $mappedDataType )",
                [],
            );
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function addColumn(string $table, string $column, DataType $dataType, bool $nullable = null, string $default = null): bool
    {
        $enclosedTableName = $this->encloseTableName($table);
        $enclosedColumnName = $this->encloseColumnName($column);
        $mappedDataType = $this->getDatatype($dataType);

        $query = "ALTER TABLE $enclosedTableName ADD $enclosedColumnName $mappedDataType";

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
        $query = "CREATE TABLE $name ( \n";

        // loop the fields
        foreach ($columns as $fieldName => $columnSettings) {
            $query .= " $fieldName ";

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
        $query .= ' CONSTRAINT pk_' . uniqid() . ' primary key ( ' . implode(' , ', $primaryKeys) . " ) \n";
        $query .= ') ';
        $query .= 'DEFAULT COLLATION BINARY_CI ';

        return $this->_pQuery($query, []);
    }

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function hasIndex($table, $name): bool
    {
        $index = iterator_to_array(
            $this->getPArray(
                'SELECT INDEX_NAME FROM USER_INDEXES WHERE TABLE_NAME = ? AND INDEX_NAME = ?',
                [strtoupper($table), strtoupper($name)],
            ),
            false,
        );

        return count($index) > 0;
    }

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function hasColumn(string $tableName, string $columnName): bool
    {
        return (bool) $this->getPArray(
            'SELECT column_name FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            [strtoupper($tableName), strtoupper($columnName)],
        );
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        $this->txOpen = true;
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
        oci_commit($this->linkDB);
        $this->txOpen = false;
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
        oci_rollback($this->linkDB);
        $this->txOpen = false;
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
     * @throws QueryException
     */
    public function getDbInfo(): array
    {
        $contextSort = iterator_to_array(
            $this->getPArray("select sys_context ('userenv', 'nls_sort') val1 from sys.dual", []),
            false,
        );
        $contextComp = iterator_to_array(
            $this->getPArray("select sys_context ('userenv', 'nls_comp') val1 from sys.dual", []),
            false,
        );

        return [
            'version' => $this->getServerVersion(),
            'dbserver' => oci_server_version($this->linkDB),
            'dbclient' => function_exists('oci_client_version') ? oci_client_version() : '',
            'nls_sort' => $contextSort[0]['val1'] ?? '-',
            'nls_comp' => $contextComp[0]['val1'] ?? '-',
        ];
    }

    /**
     * Parses the version out of the server info string.
     * @see https://github.com/doctrine/dbal/blob/master/lib/Doctrine/DBAL/Driver/OCI8/OCI8Connection.php
     */
    private function getServerVersion(): string
    {
        if (!preg_match('/\s+(\d+\.\d+\.\d+\.\d+\.\d+)\s+/', oci_server_version($this->linkDB), $version)) {
            throw new UnexpectedValueException(oci_server_version($this->linkDB));
        }

        return $version[1];
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
        $tablesString = implode(',', $tables);

        $dumpBin = (new ExecutableFinder())->find($this->dumpBin);
        $command = $dumpBin . ' ' . $this->config->getUsername() . '/' . $this->config->getPassword(
            ) . ' CONSISTENT=n TABLES=' . $tablesString . " FILE='" . $fileName . "'";

        $this->runCommand($command);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function dbImport($fileName): bool
    {
        $restoreBin = (new ExecutableFinder())->find($this->restoreBin);
        $command = $restoreBin . ' ' . $this->config->getUsername() . '/' . $this->config->getPassword(
            ) . " FILE='" . $fileName . "'";

        $this->runCommand($command);

        return true;
    }

    /**
     * Transforms the prepared statement into a valid oracle syntax.
     * This is done by replying the ?-chars by :x entries.
     */
    private function processQuery(string $query): string
    {
        return preg_replace_callback('/\?/', static function (): string {
            static $i = 0;
            $i++;
            return ':' . $i;
        }, $query);
    }

    /**
     * Does as cache-lookup for prepared statements.
     * Reduces the number of recompiles at the db-side.
     *
     * @return resource | false
     */
    private function getParsedStatement(string $query)
    {
        if (stripos($query, 'select') !== false) {
            $query = str_replace([' as ', ' AS '], [' ', ' '], $query);
        }

        return oci_parse($this->linkDB, $query);
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

        foreach ($row as $key => $val) {
            if (is_object($val) && get_class($val) === 'OCILob') {
                // Inject an anonymous lazy loader
                $row[$key] = new class($val) implements \Stringable {
                    private \OCILob $val;

                    public function __construct(\OCILob $val)
                    {
                        $this->val = $val;
                    }

                    public function __toString(): string
                    {
                        return (string) $this->val->load();
                    }
                };
            }
        }

        return $row;
    }

    /**
     * @inheritDoc
     */
    public function flushQueryCache(): void
    {
    }


    /** @var bool caching the version parse & compare */
    private static bool | null $is12c = false;

    /**
     * @inheritdoc
     */
    public function appendLimitExpression(string $query, int $start, int $end): string
    {
        if (self::$is12c === null) {
            self::$is12c = version_compare($this->getServerVersion(), '12.1', 'ge');
        }

        if (self::$is12c) {
            // TODO: 12c has a new offset syntax - lets see if it's really faster
            $delta = $end - $start + 1;
            return $query . " OFFSET $start ROWS FETCH NEXT $delta ROWS ONLY";
        }

        $start++;
        $end++;

        return 'SELECT * FROM (
                     SELECT a.*, ROWNUM rnum FROM
                        ( ' . $query . ') a
                     WHERE ROWNUM <= ' . $end . '
                )
                WHERE rnum >= ' . $start;
    }

    /**
     * @inheritdoc
     */
    public function getConcatExpression(array $parts): string
    {
        return implode(' || ', $parts);
    }

    /**
     * @inheritDoc
     */
    public function convertToDatabaseValue(mixed $value, DataType $type): mixed
    {
        return match ($type) {
            DataType::TEXT => mb_substr($value, 0, 4000),
            default => parent::convertToDatabaseValue($value, $type),
        };
    }

    /**
     * Sets the sorting and comparison of strings to case insensitive
     * @throws QueryException
     */
    private function setCaseInsensitiveSort(): void
    {
        $this->_pQuery('alter session set nls_sort=binary_ci', []);
        $this->_pQuery('alter session set nls_comp=LINGUISTIC', []);
    }

    /**
     * Sets the sorting and comparison of strings to case sensitive
     * @throws QueryException
     */
    private function setCaseSensitiveSort(): void
    {
        $this->_pQuery('alter session set nls_sort=binary', []);
        $this->_pQuery('alter session set nls_comp=ANSI', []);
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

