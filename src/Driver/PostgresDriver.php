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
use PgSql\Connection;
use Symfony\Component\Process\ExecutableFinder;

/**
 * DB-driver for postgres using the php-postgres-interface.
 */
class PostgresDriver extends DriverAbstract
{
    private Connection | false | null $linkDB;

    private ?ConnectionParameters $config = null;

    private string $dumpBin = 'pg_dump'; // Binary to dump db (if not in path, add the path here)
    private string $restoreBin = 'psql'; // Binary to restore db (if not in path, add the path here)

    private array $cxInfo = [];

    /**
     * @inheritdoc
     * @throws QueryException
     */
    public function dbconnect(ConnectionParameters $params): bool
    {
        $port = $params->getPort();
        if (empty($port)) {
            $port = 5432;
        }

        $this->config = $params;
        $this->linkDB = pg_connect(
            "host='" . $params->getHost() . "' port='" . $port . "' dbname='" . $params->getDatabase(
            ) . "' user='" . $params->getUsername() . "' password='" . $params->getPassword() . "'"
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
    public function dbclose(): void
    {
        if (is_resource($this->linkDB)) {
            pg_close($this->linkDB);
            $this->linkDB = null;
        }
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function _pQuery(string $query, array $params): bool
    {
        $query = $this->processQuery($query);

        $name = $this->getPreparedStatementName($query);
        if ($name === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

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
    public function getPArray($query, $params): Generator
    {
        $query = $this->processQuery($query);
        $name = $this->getPreparedStatementName($query);
        if ($name === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $query, $params);
        }

        $resultSet = pg_execute($this->linkDB, $name, $params);

        if ($resultSet === false) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $query, $params);
        }

        while ($row = pg_fetch_array($resultSet, null, PGSQL_ASSOC)) {
            //conversions to remain compatible:
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
    public function insertOrUpdate(string $table, array $columns, array $values, array $primaryColumns): bool
    {
        // get the current postgres version to validate the upsert features
        if (version_compare($this->cxInfo['server'], '9.5', '<')) {
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
            $strQuery = 'INSERT INTO ' . $this->encloseTableName($table) . ' (' . implode(
                    ', ',
                    $mappedColumns
                ) . ') VALUES (' . implode(
                    ', ',
                    $placeholders
                ) . ')
                        ON CONFLICT ON CONSTRAINT ' . $table . '_pkey DO NOTHING';
        } else {
            $strQuery = 'INSERT INTO ' . $this->encloseTableName($table) . ' (' . implode(
                    ', ',
                    $mappedColumns
                ) . ') VALUES (' . implode(', ', $placeholders) . ')
                        ON CONFLICT ON CONSTRAINT ' . $table . '_pkey DO UPDATE SET ' . implode(', ', $keyValuePairs);
        }

        return $this->_pQuery($strQuery, $values);
    }

    /**
     * @inheritDoc
     */
    public function getError(): string
    {
        return pg_last_error($this->linkDB);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function getTables(): array
    {
        $generator = $this->getPArray(
            "SELECT *, table_name as name FROM information_schema.tables WHERE table_schema = 'public'",
            []
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

        // fetch all columns
        $columnInfo = $this->getPArray('SELECT * FROM information_schema.columns WHERE table_name = ?', [$tableName]
        ) ?: [];
        foreach ($columnInfo as $column) {
            $table->addColumn(
                TableColumn::make($column['column_name'])
                    ->setInternalType($this->getCoreTypeForDbType($column))
                    ->setDatabaseType($this->getDatatype($this->getCoreTypeForDbType($column)))
                    ->setNullable($column['is_nullable'] === 'YES'),
            );
        }

        // fetch all indexes
        $indexes = $this->getPArray(
            "select * from pg_indexes where tablename  = ? AND indexname NOT LIKE '%_pkey'",
            [$tableName],
        ) ?: [];
        foreach ($indexes as $indexInfo) {
            $index = new TableIndex($indexInfo['indexname']);
            //scrape the columns from the indexdef
            $cols = substr(
                $indexInfo['indexdef'],
                strpos($indexInfo['indexdef'], '(') + 1,
                strpos($indexInfo['indexdef'], ')') - strpos($indexInfo['indexdef'], '(') - 1
            );
            $index->setDescription($cols);
            $table->addIndex($index);
        }

        //fetch all keys
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

        $keys = $this->getPArray($query, [$tableName]) ?: [];
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
            } elseif ($infoSchemaRow['character_maximum_length'] == '20') {
                return DataType::CHAR20;
            } elseif ($infoSchemaRow['character_maximum_length'] == '100') {
                return DataType::CHAR100;
            } elseif ($infoSchemaRow['character_maximum_length'] == '254') {
                return DataType::CHAR254;
            } elseif ($infoSchemaRow['character_maximum_length'] == '500') {
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
    public function getDatatype(DataType $type): string
    {
        return match ($type) {
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
    public function changeColumn(string $table, string $oldColumnName, string $newColumnName, DataType $newDataType): bool
    {
        $enclosedTableName = $this->encloseTableName($table);
        $enclosedNewColumnName = $this->encloseColumnName($newColumnName);

        if ($oldColumnName !== $newColumnName) {
            $enclosedOldColumnName = $this->encloseColumnName($oldColumnName);

            $bitReturn = $this->_pQuery(
                "ALTER TABLE $enclosedTableName RENAME COLUMN $enclosedOldColumnName TO $enclosedNewColumnName",
                [],
            );
        } else {
            $bitReturn = true;
        }

        return $bitReturn && $this->_pQuery(
                "ALTER TABLE $enclosedTableName ALTER COLUMN $enclosedNewColumnName TYPE " . $this->getDatatype($newDataType),
                [],
            );
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function createTable(string $name, array $columns, array $primaryKeys): bool
    {
        $query = 'CREATE TABLE ' . $this->encloseTableName($name) . " ( \n";

        //loop the fields
        foreach ($columns as $strFieldName => $arrColumnSettings) {
            $query .= " $strFieldName ";

            $query .= $this->getDatatype($arrColumnSettings[0]);

            //any default?
            if (isset($arrColumnSettings[2])) {
                $query .= 'DEFAULT ' . $arrColumnSettings[2] . ' ';
            }

            // nullable?
            if ($arrColumnSettings[1] === true) {
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
     * @inheritdoc
     * @throws QueryException
     */
    public function hasIndex($table, $name): bool
    {
        $arrIndex = iterator_to_array(
            $this->getPArray('SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?', [$table, $name]),
            false
        );

        return count($arrIndex) > 0;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function beginTransaction(): void
    {
        $strQuery = 'BEGIN';
        $this->_pQuery($strQuery, []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function commitTransaction(): void
    {
        $str_pQuery = 'COMMIT';
        $this->_pQuery($str_pQuery, []);
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    public function rollbackTransaction(): void
    {
        $strQuery = 'ROLLBACK';
        $this->_pQuery($strQuery, []);
    }

    /**
     * @inheritDoc
     */
    public function getDbInfo(): array
    {
        return pg_version($this->linkDB);
    }

    //--- DUMP & RESTORE ------------------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function dbExport(string &$fileName, array $tables): bool
    {
        $tablesString = '-t ' . implode(' -t ', $tables);

        $command = '';
        if ($this->config->getPassword() !== '') {
            if ($this->isWinOs()) {
                $command .= "SET \"PGPASSWORD=" . $this->config->getPassword() . "\" && ";
            } else {
                $command .= "PGPASSWORD=\"" . $this->config->getPassword() . "\" ";
            }
        }

        $port = $this->config->getPort();
        if (empty($port)) {
            $port = 5432;
        }

        $dumpBin = (new ExecutableFinder())->find($this->dumpBin);
        $host = $this->config->getHost();
        $username = $this->config->getUsername();
        $database = $this->config->getDatabase();

        if ($this->handlesDumpCompression()) {
            $fileName .= '.gz';
            $command .= $dumpBin . ' --clean --no-owner -h' . $host . ($username !== '' ? ' -U' . $username : '') . ' -p' . $port . ' ' . $tablesString . ' ' . $database . " | gzip > \"" . $fileName . "\"";
        } else {
            $command .= $dumpBin . ' --clean --no-owner -h' . $host . ($username !== '' ? ' -U' . $username : '') . ' -p' . $port . ' ' . $tablesString . ' ' . $database . " > \"" . $fileName . "\"";
        }

        $this->runCommand($command);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function dbImport($fileName): bool
    {
        $command = '';
        if ($this->config->getPassword() !== '') {
            if ($this->isWinOs()) {
                $command .= "SET \"PGPASSWORD=" . $this->config->getPassword() . "\" && ";
            } else {
                $command .= "PGPASSWORD=\"" . $this->config->getPassword() . "\" ";
            }
        }

        $port = $this->config->getPort();
        if (empty($port)) {
            $port = 5432;
        }

        $restoreBin = (new ExecutableFinder())->find($this->restoreBin);
        $host = $this->config->getHost();
        $username = $this->config->getUsername();
        $database = $this->config->getDatabase();

        if ($this->handlesDumpCompression() && pathinfo($fileName, PATHINFO_EXTENSION) === 'gz') {
            $command .= " gunzip -c \"" . $fileName . "\" | " . $restoreBin . ' -q -h' . $host . ($username !== '' ? ' -U' . $username : '') . ' -p' . $port . ' ' . $database;
        } else {
            $command .= $restoreBin . ' -q -h' . $host . ($username !== '' ? ' -U' . $username : '') . ' -p' . $port . ' ' . $database . " < \"" . $fileName . "\"";
        }

        $this->runCommand($command);

        return true;
    }

    public function encloseTableName($table): string
    {
        return "\"$table\"";
    }

    public function escape(mixed $value): string
    {
        return str_replace("\\", "\\\\", (string) $value);
    }

    /**
     * Transforms the query into a valid postgres-syntax
     *
     * @param string $query
     *
     * @return string
     */
    protected function processQuery(string $query): string
    {
        $query = preg_replace_callback('/\?/', static function (): string {
            static $intI = 0;
            $intI++;
            return '$' . $intI;
        }, $query);

        return str_replace(' LIKE ', ' ILIKE ', $query);
    }

    /**
     * Does as cache-lookup for prepared statements.
     * Reduces the number of pre-compiles at the db-side.
     */
    private function getPreparedStatementName(string $query): string | false
    {
        $sum = md5($query);
        if (in_array($sum, $this->statementsCache, true)) {
            return $sum;
        }

        if (pg_prepare($this->linkDB, $sum, $query)) {
            $this->statementsCache[] = $sum;
        } else {
            return false;
        }

        return $sum;
    }

    /**
     * @inheritdoc
     */
    public function appendLimitExpression(string $query, int $start, int $end): string
    {
        // calculate the end-value:
        $end = $end - $start + 1;
        // add the limits to the query

        return $query . ' LIMIT ' . $end . ' OFFSET ' . $start;
    }

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
    public function flushQueryCache(): void
    {
        $this->_pQuery('DISCARD ALL', []);

        parent::flushQueryCache();
    }
}
