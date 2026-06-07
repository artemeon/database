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
use Override;
use PgSql\Connection;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * DB-driver for postgres using the php-postgres-interface.
 *
 * @template-extends DriverAbstract<string>
 */
class PostgresDriver extends DriverAbstract
{
    private Connection | false | null $linkDB = null;
    private string $dumpBin = 'pg_dump'; // Binary to dump db (if not in path, add the path here)
    private string $restoreBin = 'psql'; // Binary to restore db (if not in path, add the path here)

    /**
     * @var array<string, int|string|null>
     */
    private array $cxInfo = [];

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function dbconnect(ConnectionParameters $params): bool
    {
        $port = $params->getPort();
        if (empty($port)) {
            $port = 5432;
        }

        $this->setConfig($params);
        $this->linkDB = pg_connect(
            "host='" . $params->getHost() . "' port='" . $port . "' dbname='" . $params->getDatabase(
            ) . "' user='" . $params->getUsername() . "' password='" . $params->getPassword() . "'",
        );

        if (!$this->linkDB) {
            throw new ConnectionException('Error connecting to database: ' . pg_last_error());
        }

        $this->_pQuery("SET client_encoding='UTF8'", []);

        $this->cxInfo = pg_version($this->linkDB);

        return true;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function dbclose(): void
    {
        if ($this->linkDB instanceof Connection) {
            pg_close($this->linkDB);
            $this->linkDB = null;
        }
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function _pQuery(string $query, array $params): bool
    {
        $query = $this->processQuery($query);

        $name = $this->getPreparedStatementName($query);
        if ($name === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        $this->assertConnected();

        $result = pg_execute($this->linkDB, $name, $params);
        if ($result === false) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        $this->affectedRowsCount = pg_affected_rows($result);

        return true;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getPArray(string $query, array $params): Generator
    {
        $query = $this->processQuery($query);
        $name = $this->getPreparedStatementName($query);
        if ($name === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        $this->assertConnected();

        $resultSet = pg_execute($this->linkDB, $name, $params);

        if ($resultSet === false) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        while ($row = pg_fetch_array($resultSet, null, PGSQL_ASSOC)) {
            // conversions to remain compatible:
            //   count --> COUNT(*)
            if (isset($row['count'])) {
                $row['COUNT(*)'] = $row['count'];
            }

            yield $row;
        }

        pg_free_result($resultSet);
    }

    /**
     * Postgres supports UPSERTS since 9.5 ({@see http://michael.otacoo.com/postgresql-2/postgres-9-5-feature-highlight-upsert/}).
     * A fallback is the base select / update method.
     *
     * @inheritDoc
     */
    #[Override]
    public function insertOrUpdate(string $table, array $columns, array $values, array $primaryColumns): bool
    {
        // get the current postgres version to validate the upsert features
        if (array_key_exists('server', $this->cxInfo) && is_string($this->cxInfo['server']) && version_compare($this->cxInfo['server'], '9.5', '<')) {
            // base implementation
            return parent::insertOrUpdate($table, $columns, $values, $primaryColumns);
        }

        $placeholders = [];
        $mappedColumns = [];
        $keyValuePairs = [];

        foreach ($columns as $i => $column) {
            $placeholders[] = '?';
            $mappedColumns[] = $this->encloseColumnName($column);

            if (!in_array($column, $primaryColumns, true)) {
                $keyValuePairs[] = $this->encloseColumnName($column) . ' = ?';
                $values[] = $values[$i];
            }
        }

        if (empty($keyValuePairs)) {
            $query = 'INSERT INTO ' . $this->encloseTableName($table) . ' (' . implode(
                ', ',
                $mappedColumns,
            ) . ') VALUES (' . implode(
                ', ',
                $placeholders,
            ) . ')
                        ON CONFLICT ON CONSTRAINT ' . $table . '_pkey DO NOTHING';
        } else {
            $query = 'INSERT INTO ' . $this->encloseTableName($table) . ' (' . implode(
                ', ',
                $mappedColumns,
            ) . ') VALUES (' . implode(', ', $placeholders) . ')
                        ON CONFLICT ON CONSTRAINT ' . $table . '_pkey DO UPDATE SET ' . implode(', ', $keyValuePairs);
        }

        return $this->_pQuery($query, $values);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getError(): string
    {
        $this->assertConnected();

        return pg_last_error($this->linkDB);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function getTables(): array
    {
        $generator = $this->getPArray(
            "SELECT *, table_name as name FROM information_schema.tables WHERE table_schema = 'public'",
            [],
        );
        $result = [];
        /** @var array{name:scalar} $row */
        foreach ($generator as $row) {
            $result[] = ['name' => strtolower((string) $row['name'])];
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
        $columnInfo = $this->getPArray('SELECT * FROM information_schema.columns WHERE table_name = ?', [$tableName]);
        /** @var array{column_name:non-empty-string,data_type:string,character_maximum_length:int|numeric-string,is_nullable:string} $column */
        foreach ($columnInfo as $column) {
            $table->addColumn(
                TableColumn::make($column['column_name'])
                    ->setInternalType($this->getCoreTypeForDbType($column))
                    ->setDatabaseType($this->getDatatype($this->getCoreTypeForDbType($column) ?? DataType::CHAR254))
                    ->setNullable($column['is_nullable'] === 'YES'),
            );
        }

        // fetch all indexes
        $indexes = $this->getPArray(
            "select * from pg_indexes where tablename  = ? AND indexname NOT LIKE '%_pkey'",
            [$tableName],
        );
        /** @var array{indexname:string,indexdef:scalar} $indexInfo */
        foreach ($indexes as $indexInfo) {
            $index = new TableIndex($indexInfo['indexname']);
            // scrape the columns from the indexdef
            $cols = substr(
                (string) $indexInfo['indexdef'],
                strpos((string) $indexInfo['indexdef'], '(') + 1,
                strpos((string) $indexInfo['indexdef'], ')') - strpos((string) $indexInfo['indexdef'], '(') - 1,
            );
            $index->setDescription($cols);
            $table->addIndex($index);
        }

        // fetch all keys
        $query = "SELECT a.attname as column_name
                    FROM pg_class t,
                         pg_class i,
                         pg_index ix,
                         pg_attribute a
                   WHERE t.oid = ix.indrelid
                     AND i.oid = ix.indexrelid
                     AND a.attrelid = t.oid
                     AND a.attnum = ANY(ix.indkey)
                     AND t.relkind = 'r'
                     AND ix.indisprimary = 't'
                     AND t.relname LIKE ?
                ORDER BY t.relname, i.relname";

        $keys = $this->getPArray($query, [$tableName]);
        /** @var array{column_name:string} $keyInfo */
        foreach ($keys as $keyInfo) {
            $key = new TableKey($keyInfo['column_name']);
            $table->addPrimaryKey($key);
        }

        return $table;
    }

    /**
     * Tries to convert a column provided by the database back to the Kajona internal type constant.
     *
     * @param array{data_type:string,character_maximum_length:int|numeric-string} $infoSchemaRow
     */
    private function getCoreTypeForDbType(array $infoSchemaRow): ?DataType
    {
        if ($infoSchemaRow['data_type'] === 'smallint') {
            return DataType::SMALLINT;
        }

        if ($infoSchemaRow['data_type'] === 'integer') {
            return DataType::INT;
        }

        if ($infoSchemaRow['data_type'] === 'bigint') {
            return DataType::BIGINT;
        }

        if ($infoSchemaRow['data_type'] === 'numeric') {
            return DataType::FLOAT;
        }

        if ($infoSchemaRow['data_type'] === 'character varying') {
            if ($infoSchemaRow['character_maximum_length'] == '10') {
                return DataType::CHAR10;
            }

            if ($infoSchemaRow['character_maximum_length'] == '20') {
                return DataType::CHAR20;
            }

            if ($infoSchemaRow['character_maximum_length'] == '100') {
                return DataType::CHAR100;
            }

            if ($infoSchemaRow['character_maximum_length'] == '254') {
                return DataType::CHAR254;
            }

            if ($infoSchemaRow['character_maximum_length'] == '500') {
                return DataType::CHAR500;
            }
        } elseif ($infoSchemaRow['data_type'] === 'text') {
            return DataType::TEXT;
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
            DataType::TINYINT, DataType::SMALLINT => ' SMALLINT ',
            DataType::INT => ' INT ',
            DataType::BIGINT => ' BIGINT ',
            DataType::FLOAT => ' NUMERIC ',
            DataType::CHAR10 => ' VARCHAR( 10 ) ',
            DataType::CHAR20 => ' VARCHAR( 20 ) ',
            DataType::CHAR100 => ' VARCHAR( 100 ) ',
            DataType::CHAR500 => ' VARCHAR( 500 ) ',
            DataType::TEXT, DataType::LONGTEXT => ' TEXT ',
            default => ' VARCHAR( 254 ) ',
        };
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function changeColumn(string $table, string $oldColumnName, string $newColumnName, DataType $newDataType): bool
    {
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

        return $output && $this->_pQuery(
            "ALTER TABLE $enclosedTableName ALTER COLUMN $enclosedNewColumnName TYPE " . $this->getDatatype($newDataType),
            [],
        );
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function createTable(string $name, array $columns, array $primaryKeys): bool
    {
        $query = 'CREATE TABLE ' . $this->encloseTableName($name) . " ( \n";

        // loop the fields
        foreach ($columns as $columnName => $columnSettings) {
            $query .= " $columnName ";

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
        $query .= ' PRIMARY KEY ( ' . implode(' , ', $primaryKeys) . " ) \n";
        $query .= ') ';

        return $this->_pQuery($query, []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function hasIndex(string $table, string $name): bool
    {
        $index = iterator_to_array(
            $this->getPArray('SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?', [$table, $name]),
            false,
        );

        return count($index) > 0;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function beginTransaction(): void
    {
        $this->_pQuery('BEGIN', []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function transactionBegin(): void
    {
        $this->beginTransaction();
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function commit(): void
    {
        $this->_pQuery('COMMIT', []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function transactionCommit(): void
    {
        $this->commit();
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function rollBack(): void
    {
        $this->_pQuery('ROLLBACK', []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
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
        $this->assertConnected();

        return pg_version($this->linkDB);
    }

    // --- DUMP & RESTORE ------------------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    #[Override]
    public function dbExport(string &$fileName, array $tables): bool
    {
        $tablesString = '';
        if (!empty($tables)) {
            $tablesString = '-t ' . implode(' -t ', array_map('escapeshellarg', $tables));
        }

        if (!$this->config instanceof ConnectionParameters) {
            throw new RuntimeException('Connection parameters not set');
        }

        $port = $this->config->getPort();
        if (empty($port)) {
            $port = 5432;
        }

        $dumpBin = $this->findExecutable($this->dumpBin);
        $dumpParams = [
            $dumpBin,
            '--clean',
            '--no-owner',
            '-h', escapeshellarg($this->config->getHost()),
            ($this->config->getUsername() === '') ? '' : '-U', escapeshellarg($this->config->getUsername()),
            '-p', escapeshellarg((string) ($this->config->getPort() ?? '')),
            '-d', escapeshellarg($this->config->getDatabase()),
            $tablesString,
        ];

        if ($this->handlesDumpCompression()) {
            $fileName .= '.gz';
            $process = new Process([
                'bash', '-c',
                sprintf(
                    '%s | gzip > %s',
                    implode(' ', $dumpParams),
                    escapeshellarg($fileName),
                ),
            ]);
        } else {
            $process = new Process(array_merge($dumpParams, ['-f', $fileName]));
        }

        $process->setEnv(['PGPASSWORD' => $this->config->getPassword()]);
        $this->runProcess($process);

        return true;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function dbImport(string $fileName): bool
    {
        if (!in_array(pathinfo($fileName, PATHINFO_EXTENSION), ['sql', 'gz'])) {
            throw new RuntimeException(trim($fileName . ' is not a valid import file'));
        }

        if (!$this->config instanceof ConnectionParameters) {
            throw new RuntimeException('Connection parameters not set');
        }

        $restoreBin = $this->findExecutable($this->restoreBin);
        if ($this->handlesDumpCompression() && pathinfo($fileName, PATHINFO_EXTENSION) === 'gz') {
            $restoreParams = [
                $restoreBin,
                '-q',
                '-h', escapeshellarg($this->config->getHost()),
                ($this->config->getUsername() === '') ? '' : '-U', escapeshellarg($this->config->getUsername()),
                (($this->config->getPort() ?? 0) > 0) ? sprintf('-p%d', (int) $this->config->getPort()) : '',
                '-d', escapeshellarg($this->config->getDatabase()),
            ];

            $psqlCommand = implode(' ', $restoreParams);
            $fileCommand = sprintf('gunzip -c %s', escapeshellarg($fileName));
            $process = new Process([
                'bash', '-c',
                sprintf('%s | %s', $fileCommand, $psqlCommand),
            ]);
        } elseif (pathinfo($fileName, PATHINFO_EXTENSION) === 'sql') {
            $restoreParams = [
                $restoreBin,
                '-q',
                '-h', $this->config->getHost(),
                ($this->config->getUsername() === '') ? '' : '-U', $this->config->getUsername(),
                (($this->config->getPort() ?? 0) > 0) ? sprintf('-p%d', (int) $this->config->getPort()) : '',
                '-d', $this->config->getDatabase(),
                '-f', $fileName,
            ];

            $process = new Process($restoreParams);
        } else {
            throw new RuntimeException(trim($fileName . ' is not a valid import file'));
        }

        $process->setEnv(['PGPASSWORD' => $this->config->getPassword()]);
        $this->runProcess($process, 'Database import failed:');

        return true;
    }

    #[Override]
    public function encloseTableName(string $table): string
    {
        return "\"$table\"";
    }

    #[Override]
    public function escape(mixed $value): string
    {
        return str_replace('\\', '\\\\', (string) $value);
    }

    /**
     * Transforms the query into a valid postgres-syntax.
     */
    protected function processQuery(string $query): string
    {
        $query = preg_replace_callback('/\?/', static function (): string {
            static $i = 0;
            $i++;

            return '$' . $i;
        }, $query) ?? '';

        return str_replace(' LIKE ', ' ILIKE ', $query);
    }

    /**
     * Does as cache-lookup for prepared statements.
     * Reduces the number of pre-compiles at the db-side.
     */
    private function getPreparedStatementName(string $query): false | string
    {
        $sum = md5($query);
        if (in_array($sum, $this->statementsCache, true)) {
            return $sum;
        }

        $this->assertConnected();

        if (pg_prepare($this->linkDB, $sum, $query)) {
            $this->statementsCache[] = $sum;
        } else {
            return false;
        }

        return $sum;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function appendLimitExpression(string $query, int $start, int $end): string
    {
        // calculate the end-value:
        $end = $end - $start + 1;
        // add the limits to the query

        return $query . ' LIMIT ' . $end . ' OFFSET ' . $start;
    }

    #[Override]
    public function getSubstringExpression(string $value, int $offset, ?int $length): string
    {
        $parameters = ['cast (' . $value . ' as text)', $offset];
        if (isset($length)) {
            $parameters[] = $length;
        }

        return 'SUBSTRING(' . implode(', ', $parameters) . ')';
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function flushQueryCache(): void
    {
        // s. https://www.postgresql.org/docs/current/sql-deallocate.html
        $this->_pQuery('DEALLOCATE ALL', []);

        parent::flushQueryCache();
    }

    #[Override]
    public function getJsonColumnExpression(string $column, string $key): string
    {
        return "CASE
        WHEN 
            $column ~ '^{.*}$' 
        THEN
            jsonb_extract_path_text($column::jsonb, '$key')::text
        ELSE
            $column
        END";
    }

    #[Override]
    public function getNthLastElementFromSlug(string $column, int $position): string
    {
        return "SPLIT_PART(REVERSE(SPLIT_PART(REVERSE($column), '/', $position)), '/', 1)";
    }

    /**
     * @phpstan-assert Connection $this->linkDB
     */
    private function assertConnected(): void
    {
        if (!$this->linkDB instanceof Connection) {
            throw new ConnectionException('Database not connected.');
        }
    }
}
