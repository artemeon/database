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
use Override;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * DB-driver for MySQL using the php-mysqli-interface.
 */
class MysqliDriver extends DriverAbstract
{
    private const int MAX_DEADLOCK_RETRY_COUNT = 10;
    private const int DEADLOCK_WAIT_TIMEOUT = 2;

    private bool $connected = false;

    private ?mysqli $linkDB = null; // DB-Link

    private string $dumpBin = 'mysqldump'; // Binary to dump db (if not in path, add the path here)

    private string $restoreBin = 'mysql'; // Binary to dump db (if not in path, add the path here)

    private string $errorMessage = '';

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
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
        $this->setConfig($params);

        $this->linkDB = new mysqli(
            $this->config->getHost(),
            $this->config->getUsername(),
            $this->config->getPassword(),
            $this->config->getDatabase(),
            $port,
        );

        if ($this->linkDB->connect_errno !== 0) {
            throw new ConnectionException('Error connecting to database: ' . $this->linkDB->connect_error);
        }

        $this->_pQuery("SET NAMES 'utf8mb4'", []);
        $this->_pQuery('SET CHARACTER SET utf8mb4', []);
        $this->_pQuery("SET character_set_connection ='utf8mb4'", []);

        $this->connected = true;

        return true;
    }

    /**
     * @inheritDoc
     */
    #[Override]
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
    #[Override]
    public function _pQuery(string $query, array $params): bool
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
            $statement->bind_param($types, ...$params);
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
        $statement->free_result();

        return $output;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getPArray(string $query, array $params): Generator
    {
        $statement = $this->getPreparedStatement($query);
        $types = '';

        if ($statement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        foreach ($params as $param) {
            $types .= 's';
        }

        if (count($params) > 0) {
            $statement->bind_param($types, ...$params);
        }

        if (!$statement->execute()) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        $result = $statement->get_result();

        while ($row = $result->fetch_assoc()) {
            yield $row;
        }

        $result->free_result();
    }

    /**
     * @inheritDoc
     */
    #[Override]
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
            $mappedColumns,
        ) . ') VALUES (' . implode(', ', $placeholders) . ')
                        ON DUPLICATE KEY UPDATE ' . implode(', ', $keyValuePairs);

        return $this->_pQuery($query, array_merge($values, $values));
    }

    /**
     * @inheritDoc
     */
    #[Override]
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
    #[Override]
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
    #[Override]
    public function getTableInformation(string $tableName): Table
    {
        $table = new Table($tableName);

        // fetch all columns
        $columnInfo = $this->getPArray("SHOW COLUMNS FROM $tableName", []);
        foreach ($columnInfo as $column) {
            $table->addColumn(
                TableColumn::make($column['Field'])
                    ->setInternalType($this->getCoreTypeForDbType($column))
                    ->setDatabaseType($this->getDatatype($this->getCoreTypeForDbType($column)))
                    ->setNullable($column['Null'] === 'YES'),
            );
        }

        // fetch all indexes
        $indexes = $this->getPArray("SHOW INDEX FROM $tableName WHERE Key_name != 'PRIMARY'", []);
        $indexAggr = [];
        foreach ($indexes as $indexInfo) {
            $indexAggr[$indexInfo['Key_name']] ??= [];
            $indexAggr[$indexInfo['Key_name']][] = $indexInfo['Column_name'];
        }
        foreach ($indexAggr as $key => $desc) {
            $index = new TableIndex($key);
            $index->setDescription(implode(', ', $desc));
            $table->addIndex($index);
        }

        // fetch all keys
        $keys = $this->getPArray("SHOW KEYS FROM $tableName WHERE Key_name = 'PRIMARY'", []);
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
    #[Override]
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
            DataType::TEXT => ' MEDIUMTEXT ',
            DataType::LONGTEXT => ' LONGTEXT ',
            default => ' VARCHAR( 254 ) ',
        };
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
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
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function createIndex(string $table, string $name, array $columns, bool $unique = false): bool
    {
        $enclosedTableName = $this->encloseTableName($table);

        return $this->_pQuery(
            "ALTER TABLE $enclosedTableName ADD " . ($unique ? 'UNIQUE' : '') . " INDEX $name (" . implode(',', $columns) . ')',
            [],
        );
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function hasIndex(string $table, string $name): bool
    {
        $index = iterator_to_array($this->getPArray("SHOW INDEX FROM $table WHERE Key_name = ?", [$name]), false);

        return count($index) > 0;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function deleteIndex(string $table, string $index): bool
    {
        return $this->_pQuery("DROP INDEX $index ON $table", []);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function beginTransaction(): void
    {
        $this->linkDB->begin_transaction();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function transactionBegin(): void
    {
        $this->beginTransaction();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function commit(): void
    {
        $this->linkDB->commit();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function transactionCommit(): void
    {
        $this->commit();
    }

    #[Override]
    public function rollBack(): void
    {
        $this->linkDB->rollback();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function transactionRollback(): void
    {
        $this->rollBack();
    }

    /**
     * @inheritDoc
     */
    #[Override]
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
    #[Override]
    public function encloseColumnName(string $column): string
    {
        return "`$column`";
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function encloseTableName(string $table): string
    {
        return "`$table`";
    }

    // --- DUMP & RESTORE ------------------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    #[Override]
    public function dbExport(string &$fileName, array $tables): bool
    {
        $dumpBin = (new ExecutableFinder())->find($this->dumpBin);
        $dumpParams = [
            $dumpBin,
            '-h', escapeshellarg($this->config->getHost()),
            '-u', escapeshellarg($this->config->getUsername()),
            ($this->config->getPassword() === '') ? '' : '-p' . escapeshellarg($this->config->getPassword()),
            '-P', $this->config->getPort(),
            escapeshellarg($this->config->getDatabase()),
            implode(' ', array_map('escapeshellarg', $tables)),
        ];

        $mysqldumpCommand = implode(' ', $dumpParams);
        if ($this->handlesDumpCompression()) {
            $fileName .= '.gz';
            $pattern = '%s | gzip > %s';
        } else {
            $pattern = '%s > %s';
        }

        $process = new Process([
            'bash', '-c',
            sprintf($pattern, $mysqldumpCommand, escapeshellarg($fileName)),
        ]);

        $this->runProcess($process, 'Database import failed:');

        return true;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function dbImport(string $fileName): bool
    {
        if (!in_array(pathinfo($fileName, PATHINFO_EXTENSION), ['sql', 'gz'])) {
            throw new \RuntimeException(trim($fileName . ' is not a valid import file'));
        }

        $restoreBin = (new ExecutableFinder())->find($this->restoreBin);

        $restoreParams = [
            $restoreBin,
            '-h', escapeshellarg($this->config->getHost()),
            '-u', escapeshellarg($this->config->getUsername()),
            ($this->config->getPassword() === '') ? '' : '-p' . escapeshellarg($this->config->getPassword()),
            '-P', $this->config->getPort(),
            escapeshellarg($this->config->getDatabase()),
        ];

        $mysqlCommand = implode(' ', $restoreParams);
        if ($this->handlesDumpCompression() && pathinfo($fileName, PATHINFO_EXTENSION) === 'gz') {
            $fileCommand = sprintf('gunzip -c %s', escapeshellarg($fileName));
        } elseif (pathinfo($fileName, PATHINFO_EXTENSION) === 'sql') {
            $fileCommand = sprintf('cat %s', escapeshellarg($fileName));
        } else {
            throw new \RuntimeException(trim($fileName . ' is not a valid import file'));
        }

        $process = new Process([
            'bash', '-c',
            sprintf('%s | %s', $fileCommand, $mysqlCommand),
        ]);

        $this->runProcess($process, 'Database import failed:');

        return true;
    }

    /**
     * Prepares a statement or uses an instance from the cache.
     */
    private function getPreparedStatement(string $query): false | mysqli_stmt
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

    #[Override]
    public function escape(mixed $value): string
    {
        return str_replace('\\', '\\\\', (string) $value);
    }

    #[Override]
    public function getJsonColumnExpression(string $column, string $key): string
    {
        return "CASE
                    WHEN JSON_VALID($column) THEN
                        JSON_UNQUOTE(JSON_EXTRACT($column, '$.$key'))
                    ELSE
                        $column
                END";
    }

    #[Override]
    public function getNthLastElementFromSlug(string $column, int $position): string
    {
        return "SUBSTRING_INDEX(SUBSTRING_INDEX($column, '/', -$position), '/', 1)";
    }
}
