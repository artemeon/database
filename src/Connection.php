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

use Artemeon\Database\Exception\AddColumnException;
use Artemeon\Database\Exception\ChangeColumnException;
use Artemeon\Database\Exception\CommitException;
use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\DriverNotFoundException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Exception\RemoveColumnException;
use Artemeon\Database\Exception\TableNotFoundException;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\Table;
use Artemeon\Database\Schema\TableIndex;
use BackedEnum;
use Generator;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

/**
 * This class handles all traffic from and to the database and takes care of a correct tx-handling
 * CHANGE WITH CARE!
 * Since version 3.4, prepared statements are supported. As a parameter-escaping, only the ? char is allowed,
 * named params are not supported at the moment.
 * Old plain queries are still allows, but will be discontinued around kajona 3.5 / 4.0. Up from kajona > 3.4.0
 * a warning will be generated when using the old apis.
 * When using prepared statements, all escaping is done by the database layer.
 * When using the old, plain queries, you have to escape all embedded arguments yourself by using dbsafeString().
 */
class Connection implements ConnectionInterface
{
    /**
     * Array to cache queries.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $queryCache = [];

    /**
     * @var array<string, list<string>>
     */
    private array $tablesCache = [];

    /**
     * Number of queries sent to the database.
     */
    private int $number = 0;

    /**
     * @var list<array{query:string,cached:bool,time:float}>
     */
    private array $queries = [];

    /**
     * Number of queries returned from cache.
     */
    private int $numberCache = 0;

    /**
     * Instance of the db-driver defined in the configs.
     */
    protected DriverInterface $dbDriver;

    /**
     * The number of transactions currently opened.
     */
    private int $numberOfOpenTransactions = 0;

    /**
     * Set to true, if a rollback is requested, but there are still open transaction.
     * In this case, the tx is rolled back, when the enclosing tx is finished.
     */
    private bool $currentTransactionIsDirty = false;

    /**
     * Flag indicating if the internal connection was set up.
     * Needed to have a proper lazy-connection initialization.
     */
    private bool $connected = false;

    /**
     * Enables or disables dbsafeString in total.
     * @internal
     */
    public static bool $dbSafeStringEnabled = true;

    /**
     * @throws DriverNotFoundException
     */
    public function __construct(
        private readonly ConnectionParameters $connectionParams,
        private readonly DriverFactory $driverFactory,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?int $debugLevel = null,
    ) {
        $this->dbDriver = $this->driverFactory->factory($this->connectionParams->getDriver());
    }

    /**
     * Destructor.
     * Handles the closing of remaining transactions and closes the DB connection.
     */
    public function close(): void
    {
        if ($this->numberOfOpenTransactions !== 0) {
            $this->dbDriver->rollBack();
            $this->logger?->warning('Rolled back open transactions on deletion of current instance of Db!');
        }

        if ($this->connected) {
            $this->logger?->info('closing database-connection');

            $this->dbDriver->dbclose();
        }
    }

    /**
     * This method connects with the database.
     *
     * @throws ConnectionException
     */
    protected function dbconnect(): void
    {
        $this->connected = $this->dbDriver->dbconnect($this->connectionParams);
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function multiInsert(string $tableName, array $columns, array $valueSets, ?array $escapes = null): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        if (count($valueSets) === 0) {
            return true;
        }

        // chunk columns down to less than 1000 params, could lead to errors on Oracle and sqlite otherwise.
        $output = true;
        $setsPerInsert = (int) floor(970 / count($columns));

        if ($setsPerInsert < 1) {
            throw new RuntimeException('At least one set per insert operation must be performed.');
        }

        foreach (array_chunk($valueSets, $setsPerInsert) as $valueSet) {
            $output = $output && $this->dbDriver->triggerMultiInsert(
                $tableName,
                $columns,
                $valueSet,
                $this,
                $escapes,
            );
        }

        return $output;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws QueryException
     */
    #[Override]
    public function insert(string $tableName, array $values, ?array $escapes = null): int
    {
        $this->multiInsert($tableName, array_keys($values), [array_values($values)], $escapes);

        return $this->getAffectedRowsCount();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function selectRow(
        string $tableName,
        array $columns,
        array $identifiers,
        bool $cached = true,
        ?array $escapes = [],
    ): ?array {
        $query = sprintf(
            'SELECT %s FROM %s WHERE %s',
            implode(
                ', ',
                array_map(
                    fn (string $columnName): string => $this->encloseColumnName($columnName),
                    $columns,
                ),
            ),
            $this->encloseTableName($tableName),
            implode(
                ' AND ',
                array_map(
                    fn (string $columnName): string => $this->encloseColumnName($columnName) . ' = ?',
                    array_keys($identifiers),
                ),
            ),
        );

        $row = $this->getPRow($query, array_values($identifiers), 0, $cached, $escapes ?? []);
        if ($row === []) {
            return null;
        }

        return $row;
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function update(string $tableName, array $values, array $identifier, ?array $escapes = null): int
    {
        if (empty($identifier)) {
            throw new InvalidArgumentException('Empty identifier for update statement');
        }

        $columns = [];
        $params = [];
        foreach ($values as $column => $value) {
            $columns[] = $column . ' = ?';
            $params[] = $value;
        }

        $condition = [];
        foreach ($identifier as $column => $value) {
            $condition[] = $column . ' = ?';
            $params[] = $value;
        }

        $query = 'UPDATE ' . $tableName . ' SET ' . implode(', ', $columns) . ' WHERE ' . implode(' AND ', $condition);

        $this->_pQuery($query, $params, $escapes ?? []);

        return $this->getAffectedRowsCount();
    }

    /**
     * @inheritDoc
     * @throws QueryException
     */
    #[Override]
    public function delete(string $tableName, array $identifier): int
    {
        if (empty($identifier)) {
            throw new InvalidArgumentException('Empty identifier for delete statement');
        }

        $condition = [];
        $params = [];
        foreach ($identifier as $column => $value) {
            $condition[] = $column . ' = ?';
            $params[] = $value;
        }

        $query = 'DELETE FROM ' . $tableName . ' WHERE ' . implode(' AND ', $condition);

        $this->_pQuery($query, $params);

        return $this->getAffectedRowsCount();
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function insertOrUpdate(string $tableName, array $columns, array $values, array $primaryColumns): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $output = $this->dbDriver->insertOrUpdate($tableName, $columns, $values, $primaryColumns);
        if (!$output) {
            $this->getError('', []);
        }

        return $output;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function _pQuery(string $query, array $params = [], array $escapes = []): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $query = $this->processQuery($query);

        // Increasing the counter
        $this->number++;

        try {
            $output = $this->dbDriver->_pQuery($query, $this->dbsafeParams($params, $escapes));
        } catch (QueryException $e) {
            $prettifiedQuery = $this->prettifyQuery($e->getQuery(), $e->getParams());

            $this->logger?->error($e->getMessage());
            $this->logger?->error('Query: ' . $prettifiedQuery);

            throw $e;
        }

        if (!$output) {
            $this->getError($query, $params);
        }

        return $output;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws QueryException
     */
    #[Override]
    public function executeStatement(string $query, array $params = []): int
    {
        $this->_pQuery($query, $params);

        return $this->dbDriver->getAffectedRowsCount();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getAffectedRowsCount(): int
    {
        return $this->dbDriver->getAffectedRowsCount();
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function getPRow(string $query, array $params = [], int $number = 0, bool $cache = true, array $escapes = []): array
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        if ($number !== 0) {
            trigger_error('The number parameter is deprecated', E_USER_DEPRECATED);
        }

        $params = $this->dbsafeParams($params, false);
        $result = $this->dbDriver->getPArray($query, $params);

        foreach ($result as $row) {
            return $row;
        }

        return [];
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function getPArray(
        string $query,
        array $params = [],
        ?int $start = null,
        ?int $end = null,
        bool $cache = true,
        array $escapes = [],
    ): array {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $startTime = microtime(true);

        // param validation
        if ((int) $start < 0) {
            $start = null;
        }

        if ((int) $end < 0) {
            $end = null;
        }

        $query = $this->processQuery($query);
        // Increasing global counter
        $this->number++;

        $queryMd5 = null;
        if ($cache) {
            $queryMd5 = md5($query . implode(',', $params) . $start . $end);
            if (isset($this->queryCache[$queryMd5])) {
                // Increasing Cache counter
                $this->numberCache++;

                $this->addQueryToList($query, $params, true, $startTime);

                return $this->queryCache[$queryMd5];
            }
        }

        if ($start !== null && $end !== null) {
            $query = $this->appendLimitExpression($query, $start, $end);
        }

        $output = $this->fetchAllAssociative($query, $this->dbsafeParams($params, $escapes));

        $this->addQueryToList($query, $params, false, $startTime);

        if ($cache) {
            $this->queryCache[$queryMd5] = $output;
        }

        return $output;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     *
     * @return Generator<list<array<non-empty-string, mixed>>>
     */
    #[Override]
    public function getGenerator(string $query, array $params = [], int $chunkSize = 2048, bool $paging = true): Generator
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $params = $this->dbsafeParams($params);
        $result = $this->dbDriver->getPArray($query, $params);
        $chunk = [];

        foreach ($result as $row) {
            $chunk[] = $row;

            if (count($chunk) === $chunkSize) {
                yield $chunk;
                $chunk = [];
                $this->flushQueryCache();
            }
        }

        if (!empty($chunk)) {
            yield $chunk;
        }
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws QueryException
     */
    #[Override]
    public function fetchAllAssociative(string $query, array $params = []): array
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $this->number++;
        $startTime = microtime(true);

        $params = $this->dbsafeParams($params, false);
        $output = iterator_to_array($this->dbDriver->getPArray($query, $params), false);

        $this->addQueryToList($query, $params, false, $startTime);

        return $output;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws QueryException
     */
    #[Override]
    public function fetchAssociative(string $query, array $params = []): array | false
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $this->number++;
        $startTime = microtime(true);

        $params = $this->dbsafeParams($params, false);
        $result = $this->dbDriver->getPArray($query, $params);

        $this->addQueryToList($query, $params, false, $startTime);

        foreach ($result as $row) {
            return $row;
        }

        return false;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws QueryException
     */
    #[Override]
    public function fetchFirstColumn(string $query, array $params = []): array
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $params = $this->dbsafeParams($params, false);
        $result = $this->dbDriver->getPArray($query, $params);

        $values = [];
        foreach ($result as $row) {
            $values[] = reset($row);
        }

        return $values;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws QueryException
     */
    #[Override]
    public function fetchOne(string $query, array $params = []): mixed
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $row = $this->fetchAssociative($query, $params);
        if ($row === false) {
            return false;
        }

        return reset($row);
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws QueryException
     */
    #[Override]
    public function iterateAssociative(string $query, array $params = []): Generator
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $params = $this->dbsafeParams($params, false);

        yield from $this->dbDriver->getPArray($query, $params);
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws QueryException
     */
    #[Override]
    public function iterateColumn(string $query, array $params = []): Generator
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $params = $this->dbsafeParams($params, false);
        $result = $this->dbDriver->getPArray($query, $params);

        foreach ($result as $row) {
            yield reset($row);
        }
    }

    /**
     * Writes the last DB-Error to the screen.
     *
     * @param list<scalar|Stringable|null> $params
     *
     * @throws QueryException
     * @throws ConnectionException
     */
    private function getError(string $query, array $params): void
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $error = $this->dbDriver->getError();

        // reprocess query
        $query = str_ireplace(
            [' from ', ' where ', ' and ', ' group by ', ' order by '],
            ["\nFROM ", "\nWHERE ", "\n\tAND ", "\nGROUP BY ", "\nORDER BY "],
            $query,
        );

        $query = $this->prettifyQuery($query, $params);

        $errorCode = "Error in query\n\n";
        $errorCode .= "Error:\n";
        $errorCode .= $error . "\n\n";
        $errorCode .= "Query:\n";
        $errorCode .= $query . "\n";
        $errorCode .= "\n\n";
        $errorCode .= 'Params: ' . implode(', ', $params) . "\n";
        $errorCode .= "Callstack:\n";
        if (function_exists('debug_backtrace')) {
            $stack = debug_backtrace();

            foreach ($stack as $value) {
                $errorCode .= ($value['file'] ?? 'n.a.') . "\n\t Row " . ($value['line'] ?? 'n.a.') . ', function ' . $value['function'] . "\n";
            }
        }

        // send a warning to the logger
        $this->logger?->error($errorCode);

        throw new QueryException($error, $query, $params);
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function beginTransaction(): void
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        // just start a new transaction, if no other transaction is open.
        if ($this->numberOfOpenTransactions === 0) {
            $this->dbDriver->beginTransaction();
        }

        // increase transaction-counter
        $this->numberOfOpenTransactions++;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function transactionBegin(): void
    {
        $this->beginTransaction();
    }

    /**
     * @inheritDoc
     * @throws CommitException
     * @throws ConnectionException
     */
    #[Override]
    public function commit(): void
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        // check, if the current tx is allowed to be committed.
        if ($this->numberOfOpenTransactions === 1) {
            $this->numberOfOpenTransactions--;

            if (!$this->currentTransactionIsDirty) {
                $this->dbDriver->commit();
            } else {
                $this->dbDriver->rollBack();
                $this->currentTransactionIsDirty = false;

                throw new CommitException('Could not commit transaction because a rollback occurred inside a nested transaction, because of this we have have executed a rollback on the complete outer transaction which may result in data loss');
            }
        } else {
            $this->numberOfOpenTransactions--;
        }
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws CommitException
     */
    #[Override]
    public function transactionCommit(): void
    {
        $this->commit();
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function rollBack(): void
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        if ($this->numberOfOpenTransactions === 1) {
            $this->dbDriver->rollBack();
            $this->currentTransactionIsDirty = false;
        } else {
            $this->currentTransactionIsDirty = true;
        }
        $this->numberOfOpenTransactions--;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function transactionRollback(): void
    {
        $this->rollBack();
    }

    #[Override]
    public function hasOpenTransactions(): bool
    {
        return $this->numberOfOpenTransactions > 0;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function hasDriver(string $class): bool
    {
        return $this->dbDriver instanceof $class;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function getTables(?string $prefix = null): array
    {
        if ($prefix === null) {
            $prefix = 'agp_';
        }

        if (!$this->connected) {
            $this->dbconnect();
        }

        if (isset($this->tablesCache[$prefix])) {
            return $this->tablesCache[$prefix];
        }

        $this->tablesCache[$prefix] = [];

        // increase global counter
        $this->number++;
        $tables = $this->dbDriver->getTables();

        foreach ($tables as $table) {
            if (str_starts_with((string) $table['name'], $prefix)) {
                $this->tablesCache[$prefix][] = $table['name'];
            }
        }

        return $this->tablesCache[$prefix];
    }

    /**
     * Looks up the columns of the given table.
     * Should return an array for each row consisting of:
     * array ("columnName", "columnType").
     *
     * @throws QueryException
     * @throws TableNotFoundException
     * @throws ConnectionException
     * @deprecated
     */
    #[Override]
    public function getColumnsOfTable(string $tableName): array
    {
        if (!$this->hasTable($tableName)) {
            throw new TableNotFoundException($tableName);
        }

        if (!$this->connected) {
            $this->dbconnect();
        }

        $table = $this->dbDriver->getTableInformation($tableName);

        $return = [];
        foreach ($table->getColumns() as $column) {
            $columnName = $column->getName();
            $return[$columnName] = [
                'columnName' => $columnName,
                'columnType' => $column->getInternalType() ?? DataType::CHAR254,
            ];
        }

        return $return;
    }

    /**
     * @inheritDoc
     * @throws TableNotFoundException
     * @throws ConnectionException
     */
    #[Override]
    public function getTableInformation(string $tableName): Table
    {
        if (!$this->hasTable($tableName)) {
            throw new TableNotFoundException($tableName);
        }

        if (!$this->connected) {
            $this->dbconnect();
        }

        return $this->dbDriver->getTableInformation($tableName);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getDatatype(DataType $type): string
    {
        return $this->dbDriver->getDatatype($type);
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function createTable(string $tableName, array $columns, array $keys, array $indices = []): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        // always lower case the table name
        $tableName = strtolower($tableName);

        // check whether table already exists
        $tables = $this->dbDriver->getTables();
        foreach ($tables as $table) {
            if ($table['name'] === $tableName) {
                return true;
            }
        }

        // create table
        $output = $this->dbDriver->createTable($tableName, $columns, $keys);
        if (!$output) {
            $this->getError('', []);
        }

        // create index
        if ($output && count($indices) > 0) {
            foreach ($indices as $index) {
                if (is_array($index)) {
                    $output = $output && $this->createIndex($tableName, 'ix_' . uniqid(), $index);
                } else {
                    $output = $output && $this->createIndex($tableName, 'ix_' . uniqid(), [$index]);
                }
            }
        }

        $this->flushTablesCache();

        return $output;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function dropTable(string $tableName): void
    {
        if (!$this->hasTable($tableName)) {
            return;
        }

        $this->_pQuery('DROP TABLE ' . $tableName);

        $this->flushTablesCache();
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function generateTableFromMetadata(Table $table): void
    {
        $columns = [];
        foreach ($table->getColumns() as $colDef) {
            $columns[$colDef->getName()] = [$colDef->getInternalType() ?? DataType::CHAR254, $colDef->isNullable()];
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
     * @throws ConnectionException
     */
    #[Override]
    public function createIndex(string $tableName, string $name, array $columns, bool $unique = false): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        if ($this->dbDriver->hasIndex($tableName, $name)) {
            return true;
        }
        $output = $this->dbDriver->createIndex($tableName, $name, $columns, $unique);
        if (!$output) {
            $this->getError('', []);
        }

        return $output;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function deleteIndex(string $table, string $index): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        return $this->dbDriver->deleteIndex($table, $index);
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function addIndex(string $table, TableIndex $index): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        return $this->dbDriver->addIndex($table, $index);
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function hasIndex(string $tableName, string $name): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        return $this->dbDriver->hasIndex($tableName, $name);
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function renameTable(string $oldName, string $newName): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $return = $this->dbDriver->renameTable($oldName, $newName);

        $this->flushTablesCache();

        return $return;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function changeColumn(string $tableName, string $oldColumnName, string $newColumnName, DataType $newDataType): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $return = $this->dbDriver->changeColumn($tableName, $oldColumnName, $newColumnName, $newDataType);

        if (!$return) {
            $error = $this->dbDriver->getError();

            throw new ChangeColumnException(
                'Could not change column: ' . $error,
                $tableName,
                $oldColumnName,
                $newColumnName,
                $newDataType,
            );
        }

        $this->flushTablesCache();

        return true;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     * @throws QueryException
     */
    #[Override]
    public function addColumn(string $table, string $column, DataType $dataType, ?bool $nullable = null, ?string $default = null): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        if ($this->hasColumn($table, $column)) {
            return true;
        }

        $return = $this->dbDriver->addColumn($table, $column, $dataType, $nullable, $default);

        if (!$return) {
            $error = $this->dbDriver->getError();

            throw new AddColumnException(
                'Could not add column: ' . $error,
                $table,
                $column,
                $dataType,
                $nullable,
                $default,
            );
        }

        $this->flushTablesCache();

        return true;
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function removeColumn(string $tableName, string $column): bool
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $return = $this->dbDriver->removeColumn($tableName, $column);

        if (!$return) {
            $error = $this->dbDriver->getError();

            throw new RemoveColumnException('Could not remove column: ' . $error, $tableName, $column);
        }

        $this->flushTablesCache();

        return true;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function hasColumn(string $tableName, string $column): bool
    {
        return $this->dbDriver->hasColumn($tableName, $column);
    }

    /**
     * @inheritDoc
     * @throws ConnectionException
     */
    #[Override]
    public function hasTable(string $tableName): bool
    {
        return in_array($tableName, $this->getTables(), true);
    }

    /**
     * Parses a query to eliminate unnecessary characters such as whitespaces.
     */
    private function processQuery(string $query): string
    {
        $query = trim($query);
        $search = ['    ', '   ', '  '];
        $replace = [' ', ' ', ' '];

        return str_replace($search, $replace, $query);
    }

    /**
     * @param list<scalar|Stringable|null> $params
     */
    private function addQueryToList(string $query, array $params, bool $cached, float $startTime): void
    {
        if ($this->debugLevel !== 100) {
            return;
        }

        $this->queries[] = [
            'query' => $this->prettifyQuery($query, $params),
            'cached' => $cached,
            'time' => round((microtime(true) - $startTime), 6),
        ];
    }

    /**
     * Queries the current db-driver about common information.
     *
     * @throws ConnectionException
     */
    #[Override]
    public function getDbInfo(): array
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        return $this->dbDriver->getDbInfo();
    }

    /**
     * Returns an array of all queries.
     */
    #[Override]
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Returns the number of queries sent to the database
     * including those solved by the cache.
     */
    #[Override]
    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * Returns the number of queries solved by the cache.
     */
    #[Override]
    public function getNumberCache(): int
    {
        return $this->numberCache;
    }

    /**
     * Returns the number of items currently in the query-cache.
     */
    #[Override]
    public function getCacheSize(): int
    {
        return count($this->queryCache);
    }

    /**
     * An internal wrapper to dbsafeString, used to process a complete array of parameters
     * as used by prepared statements.
     *
     * @param array<array-key, BackedEnum|EscapeableParameterInterface|Stringable|scalar|null> $params
     * @param list<bool>|false $escapes An array of boolean for each param, used to block the escaping of html-special chars.
     *                                  If not passed, all params will be cleaned.
     *
     * @return list<scalar|Stringable|null>
     *
     * @see Db::dbsafeString($string, $htmlSpecialChars = true)
     */
    private function dbsafeParams(array $params, array | false $escapes = []): array
    {
        $replace = [];
        foreach ($params as $key => $param) {
            if ($param instanceof BackedEnum) {
                $replace[$key] = $param->value;

                continue;
            }

            if ($param instanceof EscapeableParameterInterface && !$param->isEscape()) {
                $replace[$key] = $param->getValue();

                continue;
            }

            if ($escapes !== false) {
                if (isset($escapes[$key])) {
                    $param = $this->dbsafeString($param, $escapes[$key], false);
                } else {
                    $param = $this->dbsafeString($param, true, false);
                }
            }

            $replace[$key] = $param;
        }

        /** @var list<scalar|Stringable|null> */
        return array_values($replace);
    }

    /**
     * Makes a string db-safe.
     *
     * @return ($input is float ? float : ($input is int ? int : ($input is bool ? int<0,1> : ($input is null ? null : ($input is scalar ? string : mixed)))))
     * @deprecated we need to get rid of this
     */
    public function dbsafeString(mixed $input, bool $htmlSpecialChars = true, bool $addSlashes = true): mixed
    {
        // skip for numeric values to avoid php type juggling/autoboxing
        if (is_float($input) || is_int($input)) {
            return $input;
        }

        if (is_bool($input)) {
            return (int) $input;
        }

        if ($input === null) {
            return null;
        }

        if (!self::$dbSafeStringEnabled) {
            return $input;
        }

        // escape special chars
        if (is_scalar($input) && $htmlSpecialChars) {
            $input = html_entity_decode((string) $input, ENT_COMPAT, 'UTF-8');
            $input = htmlspecialchars($input, ENT_COMPAT, 'UTF-8');
        }

        if (is_scalar($input) && $addSlashes) {
            $input = addslashes((string) $input);
        }

        return $input;
    }

    /**
     * Method to flush the query-cache.
     */
    #[Override]
    public function flushQueryCache(): void
    {
        $this->queryCache = [];
    }

    /**
     * Method to flush the table-cache.
     * Since the tables won't change during regular operations,
     * flushing the tables cache is only required during package updates / installations.
     */
    #[Override]
    public function flushTablesCache(): void
    {
        $this->tablesCache = [];
    }

    /**
     * Helper to flush the precompiled queries stored at the db-driver.
     * Use this method with great care!
     *
     * @throws ConnectionException
     */
    #[Override]
    public function flushPreparedStatementsCache(): void
    {
        if (!$this->connected) {
            $this->dbconnect();
        }

        $this->dbDriver->flushQueryCache();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function encloseColumnName(string $column): string
    {
        return $this->dbDriver->encloseColumnName($column);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function encloseTableName(string $tableName): string
    {
        return $this->dbDriver->encloseTableName($tableName);
    }

    /**
     * Tries to validate the passed connection data.
     * May be used by other classes in order to test some credentials,
     * e.g. the installer.
     * The connection established will be closed directly and is not usable by other modules.
     */
    public function validateDatabaseConnectionData(ConnectionParameters $config): bool
    {
        try {
            $this->driverFactory->factory($config->getDriver())->dbconnect($config);

            return true;
        } catch (ConnectionException | DriverNotFoundException) {
        }

        return false;
    }

    #[Override]
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * For some database vendors we need to escape the backslash character even if we are using prepared statements. This
     * method unifies the behaviour. In order to select a column, which contains a backslash you need to escape the value
     * with this method.
     */
    #[Override]
    public function escape(mixed $value): mixed
    {
        return $this->dbDriver->escape($value);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function prettifyQuery(string $query, array $params): string
    {
        foreach ($params as $param) {
            if (is_string($param)) {
                $param = "'$param'";
            }

            if ($param === null) {
                $param = 'null';
            } elseif (is_int($param) || is_float($param)) {
                $param = (string) $param;
            }

            $pos = strpos($query, '?');
            if ($pos !== false && is_string($param)) {
                $query = substr_replace($query, $param, $pos, 1);
            }
        }

        return $query;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function appendLimitExpression(string $query, int $start, int $end): string
    {
        return $this->dbDriver->appendLimitExpression($query, $start, $end);
    }

    #[Override]
    public function getConcatExpression(array $parts): string
    {
        return $this->dbDriver->getConcatExpression($parts);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function convertToDatabaseValue(mixed $value, DataType $type): mixed
    {
        return $this->dbDriver->convertToDatabaseValue($value, $type);
    }

    #[Override]
    public function getLeastExpression(array $parts): string
    {
        return $this->dbDriver->getLeastExpression($parts);
    }

    #[Override]
    public function getSubstringExpression(string $value, int $offset, ?int $length): string
    {
        return $this->dbDriver->getSubstringExpression($value, $offset, $length);
    }

    #[Override]
    public function getStringLengthExpression(string $targetString): string
    {
        return $this->dbDriver->getStringLengthExpression($targetString);
    }

    #[Override]
    public function getJsonColumnExpression(string $column, string $key): string
    {
        return $this->dbDriver->getJsonColumnExpression($column, $key);
    }

    #[Override]
    public function getNthLastElementFromSlug(string $column, int $position): string
    {
        return $this->dbDriver->getNthLastElementFromSlug($column, $position);
    }
}
