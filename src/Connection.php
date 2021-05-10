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

use Artemeon\Database\Driver\MysqliDriver;
use Artemeon\Database\Driver\Oci8Driver;
use Artemeon\Database\Driver\PostgresDriver;
use Artemeon\Database\Driver\Sqlite3Driver;
use Artemeon\Database\Driver\SqlsrvDriver;
use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\Table;
use Artemeon\Database\Schema\TableColumn;
use Artemeon\Database\Schema\TableIndex;
use Artemeon\Database\Schema\TableKey;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServer2012Platform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Kajona\System\System\DbDatatypes;
use Psr\Log\LoggerInterface;

/**
 * @since 8.0
 */
class Connection implements ConnectionInterface
{
    private DBALConnection $connection;
    private ?LoggerInterface $logger;
    private ?int $debugLevel;
    private ?Cache $queryCache;
    private int $affectedRows = 0;

    /**
     * @param ConnectionParameters $connectionParams
     * @param LoggerInterface|null $logger
     * @param int|null $debugLevel
     * @throws Exception\DriverNotFoundException
     */
    public function __construct(ConnectionParameters $connectionParams, ?LoggerInterface $logger = null, ?int $debugLevel = null)
    {
        $this->connection = $this->newDBALConnection($connectionParams);
        $this->logger = $logger;
        $this->debugLevel = $debugLevel;
        $this->queryCache = new ArrayCache();
    }

    private function newDBALConnection(ConnectionParameters $connectionParams)
    {
        $driver = $connectionParams->getDriver();
        if ($driver === 'sqlite3') {
            $driver = 'pdo_sqlite';
        }

        $params = [
            'dbname' => $connectionParams->getDatabase(),
            'user' => $connectionParams->getUsername(),
            'password' => $connectionParams->getPassword(),
            'host' => $connectionParams->getHost(),
            'driver' => $driver,
        ];

        return DriverManager::getConnection($params);
    }

    /**
     * This method connects with the database
     *
     * @return void
     * @throws ConnectionException
     */
    protected function dbconnect()
    {
        try {
            $this->connection->connect();
        } catch (DBALException $e) {
            throw new ConnectionException('Could not connect to database', 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function multiInsert(string $strTable, array $arrColumns, array $arrValueSets, ?array $arrEscapes = null)
    {
        if (count($arrValueSets) == 0) {
            return true;
        }

        $this->affectedRows = 0;

        foreach ($arrValueSets as $valueSet) {
            $this->affectedRows+= $this->connection->insert($strTable, array_combine($arrColumns, $valueSet));
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function insert(string $tableName, array $values, ?array $escapes = null)
    {
        try {
            $this->affectedRows = $this->connection->insert($tableName, $values);
        } catch (DBALException $e) {
            throw new QueryException($e->getMessage(), '', [], $e);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function selectRow(string $tableName, array $columns, array $identifiers, bool $cached = true, ?array $escapes = []): ?array
    {
        $query = \sprintf(
            'SELECT %s FROM %s WHERE %s',
            \implode(', ', \array_map(
                function ($columnName): string {
                    return $this->encloseColumnName((string) $columnName);
                },
                $columns
            )),
            $this->encloseTableName($tableName),
            \implode(
                ' AND ',
                \array_map(
                    function (string $columnName): string {
                        return $this->encloseColumnName($columnName) . ' = ?';
                    },
                    \array_keys($identifiers)
                )
            )
        );

        $row = $this->getPRow($query, \array_values($identifiers), 0, $cached, $escapes);
        if ($row === []) {
            return null;
        }

        return $row;
    }

    /**
     * @inheritDoc
     */
    public function update(string $tableName, array $values, array $identifier, ?array $escapes = null): bool
    {
        try {
            $this->affectedRows = $this->connection->update($tableName, $values, $identifier);
        } catch (DBALException $e) {
            throw new QueryException($e->getMessage(), '', [], $e);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $tableName, array $identifier): bool
    {
        if (empty($identifier)) {
            throw new \InvalidArgumentException('Empty identifier for delete statement');
        }

        try {
            $this->affectedRows = $this->connection->delete($tableName, $identifier);
        } catch (DBALException $e) {
            throw new QueryException($e->getMessage(), '', [], $e);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns)
    {
        $row = $this->selectRow($strTable, $arrColumns, $arrPrimaryColumns);
        if (empty($row)) {
            $this->connection->insert($strTable, array_combine($arrColumns, $arrValues));
        } else {
            $this->connection->update($strTable, array_combine($arrColumns, $arrValues), $arrPrimaryColumns);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function _pQuery($strQuery, $arrParams = [], array $arrEscapes = [])
    {
        try {
            $this->affectedRows = $this->connection->executeStatement($strQuery, $arrParams);
        } catch (DBALException $e) {
            throw new QueryException($e->getMessage(), $strQuery, $arrParams, $e);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getIntAffectedRows()
    {
        return $this->affectedRows;
    }

    /**
     * @inheritDoc
     */
    public function getPRow($strQuery, $arrParams = [], $intNr = 0, $bitCache = true, array $arrEscapes = [])
    {
        if ($bitCache) {
            $qcp = new QueryCacheProfile(0, md5($strQuery), $this->queryCache);
        } else {
            $qcp = null;
        }

        $return = $this->connection->executeQuery($strQuery, $arrParams, [], $qcp)->fetchAssociative();
        if ($return === false) {
            return [];
        }

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function getPArray($strQuery, $arrParams = [], $intStart = null, $intEnd = null, $bitCache = true, array $arrEscapes = [])
    {
        if ($bitCache) {
            $qcp = new QueryCacheProfile(0, md5($strQuery), $this->queryCache);
        } else {
            $qcp = null;
        }

        if ((int)$intStart < 0) {
            $intStart = null;
        }

        if ((int)$intEnd < 0) {
            $intEnd = null;
        }

        if ($intStart !== null && $intEnd !== null && $intStart !== false && $intEnd !== false) {
            $strQuery = $this->appendLimitExpression($strQuery, $intStart, $intEnd);
        }

        return $this->connection->executeQuery($strQuery, $arrParams, [], $qcp)->fetchAllAssociative();
    }

    /**
     * @inheritDoc
     */
    public function getGenerator($query, array $params = [], $chunkSize = 2048, $paging = true)
    {
        $start = 0;
        $end = $chunkSize;

        do {
            $result = $this->getPArray($query, $params, $start, $end - 1, false);

            if (!empty($result)) {
                yield $result;
            }

            if ($paging) {
                $start += $chunkSize;
                $end += $chunkSize;
            }
        } while (!empty($result));
    }

    /**
     * @inheritDoc
     */
    public function transactionBegin()
    {
        $this->connection->beginTransaction();
    }

    /**
     * @inheritDoc
     */
    public function transactionCommit()
    {
        $this->connection->commit();
    }

    /**
     * @inheritDoc
     */
    public function transactionRollback()
    {
        $this->connection->rollBack();
    }

    public function hasOpenTransactions(): bool
    {
        return $this->connection->isTransactionActive();
    }

    /**
     * @inheritDoc
     */
    public function hasDriver(string $class): bool
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($class === MysqliDriver::class && $platform instanceof MySqlPlatform) {
            return true;
        } elseif ($class === Oci8Driver::class && $platform instanceof OraclePlatform) {
            return true;
        } elseif ($class === PostgresDriver::class && ($platform instanceof PostgreSQL94Platform)) {
            return true;
        } elseif ($class === Sqlite3Driver::class && $platform instanceof SqlitePlatform) {
            return true;
        } elseif ($class === SqlsrvDriver::class && $platform instanceof SQLServer2012Platform) {
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getTables($prefix = null)
    {
        $schema = $this->connection->getSchemaManager()->createSchema();
        return $schema->getTableNames();
    }

    /**
     * Looks up the columns of the given table.
     * Should return an array for each row consisting of:
     * array ("columnName", "columnType")
     *
     * @param string $strTableName
     * @deprecated
     *
     * @return array
     */
    public function getColumnsOfTable($strTableName)
    {
        $schema = $this->connection->getSchemaManager()->createSchema();
        $table = $schema->getTable($strTableName);

        $return = [];
        foreach ($table->getColumns() as $column) {
            $return[$column->getName()] = [
                "columnName" => $column->getName(),
                "columnType" => $this->mapLegacyType($column->getType(), $column->getLength())
            ];
        }

        return $return;
    }

    private function mapLegacyType(Type $type, ?int $length)
    {
        if ($type instanceof IntegerType) {
            return DbDatatypes::STR_TYPE_INT;
        } elseif ($type instanceof BigIntType) {
            return DbDatatypes::STR_TYPE_BIGINT;
        } elseif ($type instanceof FloatType) {
            return DbDatatypes::STR_TYPE_FLOAT;
        } elseif ($type instanceof StringType && $length === 10) {
            return DbDatatypes::STR_TYPE_CHAR10;
        } elseif ($type instanceof StringType && $length === 20) {
            return DbDatatypes::STR_TYPE_CHAR20;
        } elseif ($type instanceof StringType && $length === 100) {
            return DbDatatypes::STR_TYPE_CHAR100;
        } elseif ($type instanceof StringType && $length === 254) {
            return DbDatatypes::STR_TYPE_CHAR254;
        } elseif ($type instanceof StringType && $length === 500) {
            return DbDatatypes::STR_TYPE_CHAR500;
        } elseif ($type instanceof StringType) {
            // other string types with an unknown length
            return DbDatatypes::STR_TYPE_CHAR254;
        } elseif ($type instanceof TextType) {
            return DbDatatypes::STR_TYPE_TEXT;
        } elseif ($type instanceof BlobType) {
            return DbDatatypes::STR_TYPE_LONGTEXT;
        }

        return '';
    }

    /**
     * @inheritDoc
     */
    public function getTableInformation($tableName): Table
    {
        $schema = $this->connection->getSchemaManager()->createSchema();
        $table = $schema->getTable($tableName);

        $return = new Table($table->getName());
        foreach ($table->getColumns() as $column) {
            $col = new TableColumn($column->getName());
            $col->setDatabaseType($column->getType()->getName());
            $col->setInternalType($this->mapLegacyType($column->getType(), $column->getLength()));
            $col->setNullable($column->getNotnull() !== false);
            $return->addColumn($col);
        }

        $indexes = $table->getIndexes();
        foreach ($indexes as $index) {
            if ($index->isPrimary()) {
                $in = new TableKey($index->getName());
                $return->addPrimaryKey($in);
            } else {
                $in = new TableIndex($index->getName());
                $in->setDescription(implode(',', $index->getColumns()));
                $return->addIndex($in);
            }
        }

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function getDatatype($type)
    {
        if ($type === DataType::STR_TYPE_INT) {
            return Types::INTEGER;
        } elseif ($type === DataType::STR_TYPE_LONG) {
            return Types::BIGINT;
        } elseif ($type === DataType::STR_TYPE_DOUBLE) {
            return Types::FLOAT;
        } elseif ($type === DataType::STR_TYPE_CHAR10) {
            return Types::STRING;
        } elseif ($type === DataType::STR_TYPE_CHAR20) {
            return Types::STRING;
        } elseif ($type === DataType::STR_TYPE_CHAR100) {
            return Types::STRING;
        } elseif ($type === DataType::STR_TYPE_CHAR254) {
            return Types::STRING;
        } elseif ($type === DataType::STR_TYPE_CHAR500) {
            return Types::STRING;
        } elseif ($type === DataType::STR_TYPE_TEXT) {
            return Types::TEXT;
        } elseif ($type === DataType::STR_TYPE_LONGTEXT) {
            return Types::BLOB;
        } else {
            throw new \InvalidArgumentException('Invalid data tye');
        }
    }

    private function getOptionsByDataType(string $type): array
    {
        $options = [];
        if ($type == DataType::STR_TYPE_INT) {
        } elseif ($type == DataType::STR_TYPE_LONG) {
        } elseif ($type == DataType::STR_TYPE_DOUBLE) {
        } elseif ($type == DataType::STR_TYPE_CHAR10) {
            $options['length'] = 10;
        } elseif ($type == DataType::STR_TYPE_CHAR20) {
            $options['length'] = 20;
        } elseif ($type == DataType::STR_TYPE_CHAR100) {
            $options['length'] = 100;
        } elseif ($type == DataType::STR_TYPE_CHAR254) {
            $options['length'] = 254;
        } elseif ($type == DataType::STR_TYPE_CHAR500) {
            $options['length'] = 500;
        } elseif ($type == DataType::STR_TYPE_TEXT) {
        } elseif ($type == DataType::STR_TYPE_LONGTEXT) {
        }

        return $options;
    }

    /**
     * @inheritDoc
     */
    public function createTable($strName, $arrFields, $arrKeys, $arrIndices = array())
    {
        return $this->modifySchema(function(Schema $newSchema) use ($strName, $arrFields, $arrKeys, $arrIndices){
            if ($newSchema->hasTable($strName)) {
                return null;
            }

            $table = $newSchema->createTable($strName);

            foreach ($arrFields as $strFieldName => $arrColumnSettings) {
                $type = $this->getDatatype($arrColumnSettings[0]);
                $options = $this->getOptionsByDataType($arrColumnSettings[0]);
                $options['notnull'] = $arrColumnSettings[1] === false;

                if (isset($arrColumnSettings[2])) {
                    $options['default'] = $arrColumnSettings[2];
                }

                $table->addColumn($strFieldName, $type, $options);
            }

            $table->setPrimaryKey($arrKeys);

            foreach ($arrIndices as $index) {
                $table->addIndex((array) $index);
            }

            return $newSchema;
        });
    }

    /**
     * @inheritDoc
     */
    public function dropTable(string $tableName): void
    {
        $this->modifySchema(function(Schema $newSchema) use ($tableName) {
            if (!$newSchema->hasTable($tableName)) {
                return null;
            }

            $newSchema->dropTable($tableName);

            return $newSchema;
        });
    }

    /**
     * @inheritDoc
     */
    public function generateTableFromMetadata(Table $table): void
    {
        $columns = [];
        foreach ($table->getColumns() as $colDef) {
            $columns[$colDef->getName()] = [$colDef->getInternalType(), $colDef->isNullable()];
        }

        $primary = [];
        foreach ($table->getPrimaryKeys() as $keyDef) {
            $primary[] = $keyDef->getName();
        }

        $this->createTable($table->getName(), $columns, $primary);

        foreach ($table->getIndexes() as $indexDef) {
            $this->addIndex($table->getName(), $indexDef);
        }
    }

    /**
     * @inheritDoc
     */
    public function createIndex($strTable, $strName, array $arrColumns, $bitUnique = false)
    {
        return $this->modifySchema(static function(Schema $newSchema) use ($strTable, $strName, $arrColumns, $bitUnique){
            $table = $newSchema->getTable($strTable);

            if ($table->hasIndex($strName)) {
                return null;
            }

            if ($bitUnique) {
                $table->addUniqueIndex($arrColumns, $strName);
            } else {
                $table->addIndex($arrColumns, $strName);
            }

            return $newSchema;
        });
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $table, string $index): bool
    {
        return $this->modifySchema(static function(Schema $newSchema) use ($table, $index) {
            $table = $newSchema->getTable($table);

            if (!$table->hasIndex($index)) {
                return null;
            }

            $table->dropIndex($index);

            return $newSchema;
        });
    }

    /**
     * @inheritDoc
     */
    public function addIndex(string $table, TableIndex $index)
    {
        $this->createIndex($table, $index->getName(), explode(",", $index->getDescription()));

        return true;
    }

    /**
     * @inheritDoc
     */
    public function hasIndex($strTable, $strName): bool
    {
        $schema = $this->connection->getSchemaManager()->createSchema();
        $table = $schema->getTable($strTable);

        return $table->hasIndex($strName);
    }

    /**
     * @inheritDoc
     */
    public function renameTable($strOldName, $strNewName)
    {
        $this->connection->getSchemaManager()->renameTable($strOldName, $strNewName);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype)
    {
        throw new \RuntimeException('Changing a column is no longer possible, please create a new column and drop the old one');
    }

    /**
     * @inheritDoc
     */
    public function addColumn($strTable, $strColumn, $strDatatype, $bitNull = null, $strDefault = null)
    {
        return $this->modifySchema(function(Schema $newSchema) use ($strTable, $strColumn, $strDatatype, $bitNull, $strDefault) {
            $table = $newSchema->getTable($strTable);

            if ($table->hasColumn($strColumn)) {
                return null;
            }

            $options = [];
            if ($bitNull !== null) {
                $options['notnull'] = $bitNull !== true;
            } else {
                $options['notnull'] = false;
            }

            if ($strDefault !== null) {
                $options['default'] = $strDefault;
            }

            $table->addColumn($strColumn, $this->getDatatype($strDatatype), $options);

            return $newSchema;
        });
    }

    /**
     * @inheritDoc
     */
    public function removeColumn($strTable, $strColumn)
    {
        return $this->modifySchema(static function(Schema $newSchema) use ($strTable, $strColumn) {
            $table = $newSchema->getTable($strTable);

            if (!$table->hasColumn($strColumn)) {
                return null;
            }

            $table->dropColumn($strColumn);

            return $newSchema;
        });
    }

    /**
     * @inheritDoc
     */
    public function hasColumn($strTable, $strColumn)
    {
        $schema = $this->connection->getSchemaManager()->createSchema();
        $table = $schema->getTable($strTable);

        return $table->hasColumn($strColumn);
    }

    /**
     * @inheritDoc
     */
    public function hasTable($strTable)
    {
        $schema = $this->connection->getSchemaManager()->createSchema();
        return $schema->hasTable($strTable);
    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    public function createQueryBuilder(): QueryBuilder
    {
        return $this->connection->createQueryBuilder();
    }

    /**
     * Queries the current db-driver about common information
     *
     * @return mixed|string
     * @deprecated
     */
    public function getDbInfo()
    {
        return "";
    }


    /**
     * Returns the number of queries sent to the database
     * including those solved by the cache
     *
     * @return int
     * @deprecated
     */
    public function getNumber()
    {
        return 0;
    }

    /**
     * Returns the number of queries solved by the cache
     *
     * @return int
     * @deprecated
     */
    public function getNumberCache()
    {
        return 0;
    }

    /**
     * Returns the number of items currently in the query-cache
     *
     * @return int
     * @deprecated
     */
    public function getCacheSize()
    {
        return 0;
    }

    /**
     * Makes a string db-safe
     *
     * @param string $strString
     * @param bool $bitHtmlSpecialChars
     * @param bool $bitAddSlashes
     *
     * @return int|null|string
     * @deprecated we need to get rid of this
     */
    public function dbsafeString($strString, $bitHtmlSpecialChars = true, $bitAddSlashes = true)
    {
        return $strString;
    }

    /**
     * @deprecated
     * @return void
     */
    public function flushQueryCache()
    {
    }

    /**
     * @deprecated
     * @return void
     */
    public function flushTablesCache()
    {
    }

    /**
     * @deprecated
     * @return void
     */
    public function flushPreparedStatementsCache()
    {
    }

    /**
     * @inheritDoc
     */
    public function encloseColumnName($strColumn)
    {
        return $this->connection->quoteIdentifier($strColumn);
    }

    /**
     * @inheritDoc
     */
    public function encloseTableName($strTable)
    {
        return $this->connection->quoteIdentifier($strTable);
    }

    /**
     * Tries to validate the passed connection data.
     * May be used by other classes in order to test some credentials,
     * e.g. the installer.
     * The connection established will be closed directly and is not usable by other modules.
     *
     * @param ConnectionParameters $objCfg
     * @return bool
     */
    public function validateDbCxData(ConnectionParameters $objCfg)
    {
        $connection = $this->newDBALConnection($objCfg);
        $connection->connect();

        return $connection->isConnected();
    }

    /**
     * @inheritDoc
     */
    public function getBitConnected()
    {
        return $this->connection->isConnected();
    }

    /**
     * PLEASE DONT USE THIS, USE PREPARED STATEMENTS INSTEAD
     *
     * @deprecated
     * @param string $strValue
     * @return mixed
     */
    public function escape($strValue)
    {
        return $strValue;
    }

    /**
     * @inheritDoc
     */
    public function prettifyQuery($strQuery, $arrParams)
    {
        foreach ($arrParams as $strOneParam) {
            if (!is_numeric($strOneParam) && $strOneParam !== null) {
                $strOneParam = "'{$strOneParam}'";
            }
            if ($strOneParam === null) {
                $strOneParam = 'null';
            }

            $intPos = strpos($strQuery, '?');
            if ($intPos !== false) {
                $strQuery = substr_replace($strQuery, $strOneParam, $intPos, 1);
            }
        }

        return $strQuery;
    }

    /**
     * @inheritDoc
     */
    public function appendLimitExpression($strQuery, $intStart, $intEnd)
    {
        $limit = $intEnd - $intStart + 1;
        $offset = $intStart;

        return $this->connection->getDatabasePlatform()->modifyLimitQuery($strQuery, $limit, $offset);
    }

    /**
     * @inheritDoc
     */
    public function getConcatExpression(array $parts)
    {
        return $this->connection->getDatabasePlatform()->getConcatExpression(...$parts);
    }

    /**
     * @inheritDoc
     */
    public function convertToDatabaseValue($value, string $type)
    {
        return $this->connection->convertToDatabaseValue($value, $this->getDatatype($type));
    }

    /**
     * @inheritDoc
     */
    public function convertToPHPValue($value, string $type)
    {
        return $this->connection->convertToPHPValue($value, $this->getDatatype($type));
    }

    /**
     * @inheritDoc
     */
    public function getLeastExpression(array $parts): string
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof SqlitePlatform) {
            return 'MIN(' . implode(', ', $parts) . ')';
        } elseif ($platform instanceof SQLServer2012Platform) {
            return '(SELECT MIN(x) FROM (VALUES (' . implode('),(', $parts) . ')) AS value(x))';
        } else {
            return 'LEAST(' . implode(', ', $parts) . ')';
        }
    }

    public function getSubstringExpression(string $value, int $offset, ?int $length): string
    {
        return $this->connection->getDatabasePlatform()->getSubstringExpression($value, $offset, $length);
    }

    private function modifySchema(\Closure $modifier): bool
    {
        $schema = $this->connection->createSchemaManager()->createSchema();
        $newSchema = clone $schema;

        $newSchema = $modifier($newSchema);

        if ($newSchema === null) {
            return true;
        }

        $queries = $schema->getMigrateToSql($newSchema, $this->connection->getDatabasePlatform());
        foreach ($queries as $query) {
            $this->connection->executeStatement($query);
        }

        return true;
    }
}
