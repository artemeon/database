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
use mysqli;
use mysqli_stmt;
use Symfony\Component\Process\ExecutableFinder;

/**
 * DB-driver for MySQL using the php-mysqli-interface.
 */
class MysqliDriver extends DriverAbstract
{
    private const MAX_DEADLOCK_RETRY_COUNT = 10;
    private const DEADLOCK_WAIT_TIMEOUT = 2;

    private bool $connected = false;

    private ?mysqli $linkDB; //DB-Link

    private ?ConnectionParameters $config;

    private string $dumpBin = 'mysqldump'; // Binary to dump db (if not in path, add the path here)

    private string $restoreBin = 'mysql'; // Binary to dump db (if not in path, add the path here)

    private string $errorMessage = '';

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function dbconnect(ConnectionParameters $params): bool
    {
        if ($this->connected) {
            return true;
        }

        $port = $params->getPort();
        if (empty($port)) {
            $port = 3306;
        }

        // Save connection-details
        $this->config = $params;

        $this->linkDB = new mysqli(
            $this->config->getHost(),
            $this->config->getUsername(),
            $this->config->getPassword(),
            $this->config->getDatabase(),
            $port
        );

        if ($this->linkDB->connect_errno) {
            throw new ConnectionException('Error connecting to database: ' . $this->linkDB->connect_error);
        }

        // erst ab mysql-client-bib > 4
        // mysqli_set_charset($this->linkDB, "utf8");
        $this->_pQuery("SET NAMES 'utf8mb4'", []);
        $this->_pQuery('SET CHARACTER SET utf8mb4', []);
        $this->_pQuery("SET character_set_connection ='utf8mb4'", []);
        // $this->_pQuery("SET character_set_database ='utf8mb4'", []);
        // $this->_pQuery("SET character_set_server ='utf8mb4'", []);

        $this->connected = true;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function dbclose(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->linkDB->close();
        $this->linkDB = null;
        $this->connected = false;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function _pQuery($query, $params): bool
    {
        $statement = $this->getPreparedStatement($query);
        if ($statement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        $output = false;
        $types = '';
        foreach ($params as $param) {
            if (is_float($param)) {
                $types .= 'd';
            } elseif (is_int($param)) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
        }

        if (count($params) > 0) {
            $params = array_merge([$types], $params);
            call_user_func_array([$statement, 'bind_param'], $this->refValues($params));
        }

        $count = 0;
        while ($count < self::MAX_DEADLOCK_RETRY_COUNT) {
            $output = $statement->execute();
            if ($output === false && $statement->errno === 1213) {
                // in case we have a deadlock wait for a bit and retry the query.
                $count++;
                sleep(self::DEADLOCK_WAIT_TIMEOUT);
            } else {
                break;
            }
        }

        if ($output === false) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        $this->affectedRowsCount = $statement->affected_rows;

        return $output;
    }

    /**
     * @inheritDoc
     */
    public function getPArray(string $query, array $params): Generator
    {
        $statement = $this->getPreparedStatement($query);
        if ($statement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        $types = '';
        foreach ($params as $param) {
            $types .= 's';
        }

        if (count($params) > 0) {
            $params = array_merge([$types], $params);
            call_user_func_array([$statement, 'bind_param'], $this->refValues($params));
        }

        if (!$statement->execute()) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        //should remain here due to the bug http://bugs.php.net/bug.php?id=47928
        $statement->store_result();

        $metadata = $statement->result_metadata();
        $params = [];
        $row = [];

        if ($metadata === false) {
            $statement->free_result();
            return [];
        }

        while ($metadata && $field = $metadata->fetch_field()) {
            $params[] = &$row[$field->name];
        }

        call_user_func_array([$statement, 'bind_result'], $params);

        while ($statement->fetch()) {
            $singleRow = [];
            foreach ($row as $key => $val) {
                $singleRow[$key] = $val;
            }
            yield $singleRow;
        }

        $statement->free_result();
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate(string $table, array $columns, array $values, array $primaryColumns): bool
    {
        $placeholders = [];
        $mappedColumns = [];
        $keyValuePairs = [];

        foreach ($columns as $column) {
            $placeholders[] = '?';
            $mappedColumns[] = $this->encloseColumnName($column);
            $keyValuePairs[] = $this->encloseColumnName($column) . ' = ?';
        }

        $enclosedTableName = $this->encloseTableName($table);

        $query = "INSERT INTO $enclosedTableName (" . implode(
                ', ',
                $mappedColumns
            ) . ') VALUES (' . implode(', ', $placeholders) . ')
                        ON DUPLICATE KEY UPDATE ' . implode(', ', $keyValuePairs);

        return $this->_pQuery($query, array_merge($values, $values));
    }

    /**
     * @inheritDoc
     */
    public function getError(): string
    {
        $error = $this->errorMessage . ' ' . $this->linkDB->error;
        $this->errorMessage = '';

        return $error;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function getTables(): array
    {
        $generator = $this->getPArray('SHOW TABLE STATUS', []);
        $result = [];
        foreach ($generator as $row) {
            $result[] = ['name' => $row['Name']];
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
        $columnInfo = $this->getPArray("SHOW COLUMNS FROM $tableName", []) ?: [];
        foreach ($columnInfo as $column) {
            $table->addColumn(
                TableColumn::make($column['Field'])
                    ->setInternalType($this->getCoreTypeForDbType($column))
                    ->setDatabaseType($this->getDatatype($this->getCoreTypeForDbType($column)))
                    ->setNullable($column['Null'] === 'YES'),
            );
        }

        //fetch all indexes
        $indexes = $this->getPArray("SHOW INDEX FROM $tableName WHERE Key_name != 'PRIMARY'", []) ?: [];
        $indexAggr = [];
        foreach ($indexes as $indexInfo) {
            $indexAggr[$indexInfo['Key_name']] = $indexAggr[$indexInfo['Key_name']] ?? [];
            $indexAggr[$indexInfo['Key_name']][] = $indexInfo['Column_name'];
        }
        foreach ($indexAggr as $key => $desc) {
            $index = new TableIndex($key);
            $index->setDescription(implode(', ', $desc));
            $table->addIndex($index);
        }

        //fetch all keys
        $keys = $this->getPArray("SHOW KEYS FROM $tableName WHERE Key_name = 'PRIMARY'", []) ?: [];
        foreach ($keys as $keyInfo) {
            $key = new TableKey($keyInfo['Column_name']);
            $table->addPrimaryKey($key);
        }

        return $table;
    }

    /**
     * Tries to convert a column provided by the database back to the Kajona internal type constant.
     */
    private function getCoreTypeForDbType(array $infoSchemaRow): ?DataType
    {
        if ($infoSchemaRow['Type'] === 'int(11)' || $infoSchemaRow['Type'] === 'int') {
            return DataType::INT;
        }

        if ($infoSchemaRow['Type'] === 'bigint(20)' || $infoSchemaRow['Type'] === 'bigint') {
            return DataType::BIGINT;
        }

        if ($infoSchemaRow['Type'] === 'double') {
            return DataType::FLOAT;
        }

        if ($infoSchemaRow['Type'] === 'varchar(10)') {
            return DataType::CHAR10;
        }

        if ($infoSchemaRow['Type'] === 'varchar(20)') {
            return DataType::CHAR20;
        }

        if ($infoSchemaRow['Type'] === 'varchar(100)') {
            return DataType::CHAR100;
        }

        if ($infoSchemaRow['Type'] === 'varchar(254)') {
            return DataType::CHAR254;
        }

        if ($infoSchemaRow['Type'] === 'varchar(500)') {
            return DataType::CHAR500;
        }

        if ($infoSchemaRow['Type'] === 'text') {
            return DataType::TEXT;
        }

        if ($infoSchemaRow['Type'] === 'mediumtext') {
            return DataType::TEXT;
        }

        if ($infoSchemaRow['Type'] === 'longtext') {
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
            DataType::INT => ' INT ',
            DataType::BIGINT => ' BIGINT ',
            DataType::FLOAT => ' DOUBLE ',
            DataType::CHAR10 => ' VARCHAR( 10 ) ',
            DataType::CHAR20 => ' VARCHAR( 20 ) ',
            DataType::CHAR100 => ' VARCHAR( 100 ) ',
            DataType::CHAR500 => ' VARCHAR( 500 ) ',
            DataType::TEXT => ' TEXT ',
            DataType::LONGTEXT => ' LONGTEXT ',
            default => ' VARCHAR( 254 ) ',
        };
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function createTable(string $name, array $columns, array $primaryKeys): bool
    {
        $query = 'CREATE TABLE IF NOT EXISTS `' . $name . "` ( \n";

        foreach ($columns as $fieldName => $columnSettings) {
            $query .= ' `' . $fieldName . '` ';

            $query .= $this->getDatatype($columnSettings[0]);

            // any default?
            if (isset($columnSettings[2])) {
                $query .= 'DEFAULT ' . $columnSettings[2] . ' ';
            }

            // nullable?
            if ($columnSettings[1] === true) {
                $query .= " NULL , \n";
            } else {
                $query .= " NOT NULL , \n";
            }
        }

        // primary keys
        $query .= ' PRIMARY KEY ( `' . implode('` , `', $primaryKeys) . "` ) \n";
        $query .= ') ';
        $query .= ' ENGINE = innodb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;';

        return $this->_pQuery($query, []);
    }

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false): bool
    {
        $enclosedTableName = $this->encloseTableName($table);

        return $this->_pQuery(
            "ALTER TABLE $enclosedTableName ADD " . ($unique ? 'UNIQUE' : '') . " INDEX $name (" . implode(',', $columns) . ')',
            [],
        );
    }

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function hasIndex($table, $name): bool
    {
        $index = iterator_to_array($this->getPArray("SHOW INDEX FROM $table WHERE Key_name = ?", [$name]), false);
        return count($index) > 0;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function deleteIndex(string $table, string $index): bool
    {
        return $this->_pQuery("DROP INDEX $index ON $table", []);
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        $this->linkDB->begin_transaction();
    }

    /**
     * @inheritDoc
     */
    public function commitTransaction(): void
    {
        $this->linkDB->commit();
    }

    /**
     * @inheritDoc
     */
    public function rollbackTransaction(): void
    {
        $this->linkDB->rollback();
    }

    /**
     * @inheritDoc
     */
    public function getDbInfo(): array
    {
        return [
            'dbbserver' => 'MySQL ' . $this->linkDB->server_info,
            'server_version' => $this->linkDB->server_version,
            'dbclient' => $this->linkDB->client_info,
            'client_version' => $this->linkDB->client_info,
            'dbconnection' => $this->linkDB->host_info,
            'protocol_version' => $this->linkDB->protocol_version,
            'thread_id' => $this->linkDB->thread_id,
        ];
    }

    /**
     * @inheritDoc
     */
    public function encloseColumnName(string $column): string
    {
        return "`$column`";
    }

    /**
     * @inheritDoc
     */
    public function encloseTableName(string $table): string
    {
        return "`$table`";
    }

    //--- DUMP & RESTORE ------------------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function dbExport(string &$fileName, array $tables): bool
    {
        $tablesString = implode(' ', $tables);
        $paramPass = '';

        if ($this->config->getPassword() !== '') {
            $paramPass = " -p\"" . $this->config->getPassword() . "\"";
        }

        $dumpBin = (new ExecutableFinder())->find($this->dumpBin);

        if ($this->handlesDumpCompression()) {
            $fileName .= '.gz';
            $command = $dumpBin . ' -h' . $this->config->getHost() . ' -u' . $this->config->getUsername(
                ) . $paramPass . ' -P' . $this->config->getPort() . ' ' . $this->config->getDatabase(
                ) . ' ' . $tablesString . " | gzip > \"" . $fileName . "\"";
        } else {
            $command = $dumpBin . ' -h' . $this->config->getHost() . ' -u' . $this->config->getUsername(
                ) . $paramPass . ' -P' . $this->config->getPort() . ' ' . $this->config->getDatabase(
                ) . ' ' . $tablesString . " > \"" . $fileName . "\"";
        }

        $this->runCommand($command);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function dbImport(string $fileName): bool
    {
        $paramPass = '';

        if ($this->config->getPassword() !== '') {
            $paramPass = " -p\"" . $this->config->getPassword() . "\"";
        }

        $restoreBin = (new ExecutableFinder())->find($this->restoreBin);

        if ($this->handlesDumpCompression() && pathinfo($fileName, PATHINFO_EXTENSION) === 'gz') {
            $command = " gunzip -c \"" . $fileName . "\" | " . $restoreBin . ' -h' . $this->config->getHost(
                ) . ' -u' . $this->config->getUsername() . $paramPass . ' -P' . $this->config->getPort(
                ) . ' ' . $this->config->getDatabase();
        } else {
            $command = $restoreBin . ' -h' . $this->config->getHost() . ' -u' . $this->config->getUsername(
                ) . $paramPass . ' -P' . $this->config->getPort() . ' ' . $this->config->getDatabase(
                ) . " < \"" . $fileName . "\"";
        }

        $this->runCommand($command);

        return true;
    }

    /**
     * Converts a simple array into an array of references.
     * Required for PHP > 5.3.
     */
    private function refValues(array $values): array
    {
        if (strnatcmp(PHP_VERSION, '5.3') >= 0) { // Reference is required for PHP 5.3+
            $refs = [];
            foreach ($values as $key => $value) {
                $refs[$key] = &$values[$key];
            }
            return $refs;
        }
        return $values;
    }

    /**
     * Prepares a statement or uses an instance from the cache.
     */
    private function getPreparedStatement(string $query): mysqli_stmt | false
    {
        $name = md5($query);

        if (isset($this->statementsCache[$name])) {
            return $this->statementsCache[$name];
        }

        if (count($this->statementsCache) > 300) {
            /** @var mysqli_stmt $objOneEntry */
            foreach ($this->statementsCache as $objOneEntry) {
                $objOneEntry->close();
            }

            $this->statementsCache = [];
        }

        $statement = $this->linkDB->stmt_init();
        if (!$statement->prepare($query)) {
            $this->errorMessage = $statement->error;

            return false;
        }

        $this->statementsCache[$name] = $statement;

        return $statement;
    }

    public function escape(mixed $value): string
    {
        return str_replace("\\", "\\\\", (string) $value);
    }
}
