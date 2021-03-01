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
use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\DriverNotFoundException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Exception\RemoveColumnException;
use Artemeon\Database\Schema\Table;
use Artemeon\Database\Schema\TableIndex;
use Psr\Log\LoggerInterface;

/**
 * This class handles all traffic from and to the database and takes care of a correct tx-handling
 * CHANGE WITH CARE!
 * Since version 3.4, prepared statments are supported. As a parameter-escaping, only the ? char is allowed,
 * named params are not supported at the moment.
 * Old plain queries are still allows, but will be discontinued around kajona 3.5 / 4.0. Up from kajona > 3.4.0
 * a warning will be generated when using the old apis.
 * When using prepared statements, all escaping is done by the database layer.
 * When using the old, plain queries, you have to escape all embedded arguments yourself by using dbsafeString()
 *
 * @package module_system
 * @author sidler@mulchprod.de
 */
class Connection implements ConnectionInterface
{
    private $arrQueryCache = array(); //Array to cache queries
    private $arrTablesCache = [];
    private $intNumber = 0; //Number of queries send to database
    private $intNumberCache = 0; //Number of queries returned from cache

    /**
     * @var ConnectionParameters
     */
    private $connectionParams;

    /**
     * @var DriverFactory
     */
    private $driverFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $debugLevel;

    /**
     * Instance of the db-driver defined in the configs
     *
     * @var DriverInterface
     */
    protected $objDbDriver = null; //An object of the db-driver defined in the configs

    /**
     * The number of transactions currently opened
     *
     * @var int
     */
    private $intNumberOfOpenTransactions = 0; //The number of transactions opened

    /**
     * Set to true, if a rollback is requested, but there are still open tx.
     * In this case, the tx is rolled back, when the enclosing tx is finished
     *
     * @var bool
     */
    private $bitCurrentTxIsDirty = false;

    /**
     * Flag indicating if the internal connection was setup.
     * Needed to have a proper lazy-connection initialization.
     *
     * @var bool
     */
    private $bitConnected = false;

    /**
     * Enables or disables dbsafeString in total
     * @var bool
     * @internal
     */
    public static $bitDbSafeStringEnabled = true;

    /**
     * @param ConnectionParameters $connectionParams
     * @param DriverFactory $driverFactory
     * @param LoggerInterface|null $logger
     * @param int|null $debugLevel
     * @throws Exception\DriverNotFoundException
     */
    public function __construct(ConnectionParameters $connectionParams, DriverFactory $driverFactory, ?LoggerInterface $logger = null, ?int $debugLevel = null)
    {
        $this->connectionParams = $connectionParams;
        $this->driverFactory = $driverFactory;
        $this->logger = $logger;
        $this->debugLevel = $debugLevel;
        $this->objDbDriver = $this->driverFactory->factory($this->connectionParams->getDriver());
    }

    /**
     * Destructor.
     * Handles the closing of remaining tx and closes the db-connection
     */
    public function close()
    {
        if ($this->intNumberOfOpenTransactions != 0) {
            //something bad happened. rollback, plz
            $this->objDbDriver->transactionRollback();
            if ($this->logger !== null) {
                $this->logger->warning("Rolled back open transactions on deletion of current instance of Db!");
            }
        }


        if ($this->objDbDriver !== null && $this->bitConnected) {
            if ($this->logger !== null) {
                $this->logger->info("closing database-connection");
            }

            $this->objDbDriver->dbclose();
        }

    }

    /**
     * This method connects with the database
     *
     * @return void
     */
    protected function dbconnect()
    {
        $this->bitConnected = $this->objDbDriver->dbconnect($this->connectionParams);
    }

    /**
     * @inheritDoc
     */
    public function multiInsert(string $strTable, array $arrColumns, array $arrValueSets, ?array $arrEscapes = null)
    {
        if (count($arrValueSets) == 0) {
            return true;
        }

        //chunk columns down to less then 1000 params, could lead to errors on oracle and sqlite otherwise
        $bitReturn = true;
        $intSetsPerInsert = (int) floor(970 / count($arrColumns));

        foreach (array_chunk($arrValueSets, $intSetsPerInsert) as $arrSingleValueSet) {
            $bitReturn = $bitReturn && $this->objDbDriver->triggerMultiInsert($strTable, $arrColumns, $arrSingleValueSet, $this, $arrEscapes);
        }

        return $bitReturn;
    }

    /**
     * @inheritDoc
     */
    public function insert(string $tableName, array $values, ?array $escapes = null)
    {
        return $this->multiInsert($tableName, array_keys($values), [array_values($values)], $escapes);
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
        if (empty($identifier)) {
            throw new \InvalidArgumentException('Empty identifier for update statement');
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

        return $this->_pQuery($query, $params, $escapes ?? []);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $tableName, array $identifier): bool
    {
        if (empty($identifier)) {
            throw new \InvalidArgumentException('Empty identifier for delete statement');
        }

        $condition = [];
        $params = [];
        foreach ($identifier as $column => $value) {
            $condition[] = $column . ' = ?';
            $params[] = $value;
        }

        $query = 'DELETE FROM ' . $tableName . ' WHERE ' . implode(' AND ', $condition);

        return $this->_pQuery($query, $params);
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns)
    {
        $bitReturn = $this->objDbDriver->insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns);
        if (!$bitReturn) {
            $this->getError("", array());
        }

        return $bitReturn;
    }

    /**
     * @inheritDoc
     */
    public function _pQuery($strQuery, $arrParams = [], array $arrEscapes = [])
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        $bitReturn = false;

        $strQuery = $this->processQuery($strQuery);

        $queryId = '';
        if ($this->logger !== null) {
            $queryId = uniqid();
            $this->logger->info($queryId." ".$this->prettifyQuery($strQuery, $arrParams));
        }

        //Increasing the counter
        $this->intNumber++;

        if ($this->objDbDriver != null) {
            $bitReturn = $this->objDbDriver->_pQuery($strQuery, $this->dbsafeParams($arrParams, $arrEscapes));
        }

        if (!$bitReturn) {
            $this->getError($strQuery, $arrParams);
        }

        if ($this->logger !== null) {
            $this->logger->info($queryId." "."Query finished");
        }

        return $bitReturn;
    }

    /**
     * @inheritDoc
     */
    public function getIntAffectedRows()
    {
        return $this->objDbDriver->getIntAffectedRows();
    }

    /**
     * @inheritDoc
     */
    public function getPRow($strQuery, $arrParams = [], $intNr = 0, $bitCache = true, array $arrEscapes = [])
    {
        if ($intNr !== 0) {
            trigger_error("The intNr parameter is deprecated", E_USER_DEPRECATED);
        }

        $resultRow = $this->getPArray($strQuery, $arrParams, $intNr, $intNr, $bitCache, $arrEscapes);
        $value = current($resultRow);
        return $value !== false ? $value : [];
    }

    /**
     * @inheritDoc
     */
    public function getPArray($strQuery, $arrParams = [], $intStart = null, $intEnd = null, $bitCache = true, array $arrEscapes = [])
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        //param validation
        if ((int)$intStart < 0) {
            $intStart = null;
        }

        if ((int)$intEnd < 0) {
            $intEnd = null;
        }


        $strQuery = $this->processQuery($strQuery);
        //Increasing global counter
        $this->intNumber++;

        $strQueryMd5 = null;
        if ($bitCache) {
            $strQueryMd5 = md5($strQuery.implode(",", $arrParams).$intStart.$intEnd);
            if (isset($this->arrQueryCache[$strQueryMd5])) {
                //Increasing Cache counter
                $this->intNumberCache++;
                return $this->arrQueryCache[$strQueryMd5];
            }
        }

        $arrReturn = array();

        $queryId = '';
        if ($this->logger !== null) {
            $queryId = uniqid();
            $this->logger->info($queryId." ".$this->prettifyQuery($strQuery, $arrParams));
        }

        if ($this->objDbDriver != null) {
            if ($intStart !== null && $intEnd !== null && $intStart !== false && $intEnd !== false) {
                $arrReturn = $this->objDbDriver->getPArraySection($strQuery, $this->dbsafeParams($arrParams, $arrEscapes), $intStart, $intEnd);
            } else {
                $arrReturn = $this->objDbDriver->getPArray($strQuery, $this->dbsafeParams($arrParams, $arrEscapes));
            }

            if ($this->logger !== null) {
                $this->logger->info($queryId." "."Query finished");
            }

            if ($arrReturn === false) {
                $this->getError($strQuery, $arrParams);
                return array();
            }
            if ($bitCache) {
                $this->arrQueryCache[$strQueryMd5] = $arrReturn;
            }
        }
        return $arrReturn;
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

            $this->flushQueryCache();
        } while (!empty($result));
    }

    /**
     * Writes the last DB-Error to the screen
     *
     * @param string $strQuery
     * @throws QueryException
     * @return void
     */
    private function getError($strQuery, $arrParams)
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        $strError = "";
        if ($this->objDbDriver != null) {
            $strError = $this->objDbDriver->getError();
        }

        //reprocess query
        $strQuery = str_ireplace(
            array(" from ", " where ", " and ", " group by ", " order by "),
            array("\nFROM ", "\nWHERE ", "\n\tAND ", "\nGROUP BY ", "\nORDER BY "),
            $strQuery
        );

        $strQuery = $this->prettifyQuery($strQuery, $arrParams);

        $strErrorCode = "";
        $strErrorCode .= "Error in query\n\n";
        $strErrorCode .= "Error:\n";
        $strErrorCode .= $strError."\n\n";
        $strErrorCode .= "Query:\n";
        $strErrorCode .= $strQuery."\n";
        $strErrorCode .= "\n\n";
        $strErrorCode .= "Params: ".implode(", ", $arrParams)."\n";
        $strErrorCode .= "Callstack:\n";
        if (function_exists("debug_backtrace")) {
            $arrStack = debug_backtrace();

            foreach ($arrStack as $intPos => $arrValue) {
                $strErrorCode .= (isset($arrValue["file"]) ? $arrValue["file"] : "n.a.")."\n\t Row ".(isset($arrValue["line"]) ? $arrValue["line"] : "n.a.").", function ".$arrStack[$intPos]["function"]."\n";
            }
        }

        //send a warning to the logger
        if ($this->logger !== null) {
            $this->logger->warning($strErrorCode);
        }

        throw new QueryException($strError, $strQuery, $arrParams);
    }

    /**
     * @inheritDoc
     */
    public function transactionBegin()
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        if ($this->objDbDriver != null) {
            //just start a new tx, if no other tx is open
            if ($this->intNumberOfOpenTransactions == 0) {
                $this->objDbDriver->transactionBegin();
            }

            //increase tx-counter
            $this->intNumberOfOpenTransactions++;

        }
    }

    /**
     * @inheritDoc
     */
    public function transactionCommit()
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        if ($this->objDbDriver != null) {
            //check, if the current tx is allowed to be commited
            if ($this->intNumberOfOpenTransactions == 1) {
                //so, this is the last remaining tx. Commit or rollback?
                if (!$this->bitCurrentTxIsDirty) {
                    $this->objDbDriver->transactionCommit();
                } else {
                    $this->objDbDriver->transactionRollback();
                    $this->bitCurrentTxIsDirty = false;
                }

                //decrement counter
                $this->intNumberOfOpenTransactions--;
            } else {
                $this->intNumberOfOpenTransactions--;
            }

        }
    }

    /**
     * @inheritDoc
     */
    public function transactionRollback()
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        if ($this->objDbDriver != null) {
            if ($this->intNumberOfOpenTransactions == 1) {
                //so, this is the last remaining tx. rollback anyway
                $this->objDbDriver->transactionRollback();
                $this->bitCurrentTxIsDirty = false;
                //decrement counter
                $this->intNumberOfOpenTransactions--;
            } else {
                //mark the current tx session a dirty
                $this->bitCurrentTxIsDirty = true;
                //decrement the number of open tx
                $this->intNumberOfOpenTransactions--;
            }

        }
    }

    public function hasOpenTransactions(): bool
    {
        return $this->intNumberOfOpenTransactions > 0;
    }

    /**
     * @inheritDoc
     */
    public function hasDriver(string $class): bool
    {
        return $this->objDbDriver instanceof $class;
    }

    /**
     * @inheritDoc
     */
    public function getTables($prefix = null)
    {
        if ($prefix === null) {
            $prefix = "agp_";
        }

        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        if (isset($this->arrTablesCache[$prefix])) {
            return $this->arrTablesCache[$prefix];
        }

        $this->arrTablesCache[$prefix] = [];

        if ($this->objDbDriver != null) {
            // increase global counter
            $this->intNumber++;
            $arrTemp = $this->objDbDriver->getTables();

            foreach ($arrTemp as $arrTable) {
                if (substr($arrTable["name"], 0, strlen($prefix)) === $prefix) {
                    $this->arrTablesCache[$prefix][] = $arrTable["name"];
                }
            }
        }

        return $this->arrTablesCache[$prefix];
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
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        $table = $this->objDbDriver->getTableInformation($strTableName);

        $return = [];
        foreach ($table->getColumns() as $column) {
            $return[$column->getName()] = [
                "columnName" => $column->getName(),
                "columnType" => $column->getInternalType()
            ];
        }

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function getTableInformation($tableName): Table
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        return $this->objDbDriver->getTableInformation($tableName);
    }

    /**
     * @inheritDoc
     */
    public function getDatatype($strType)
    {
        return $this->objDbDriver->getDatatype($strType);
    }

    /**
     * @inheritDoc
     */
    public function createTable($strName, $arrFields, $arrKeys, $arrIndices = array())
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        // check whether table already exists
        $arrTables = $this->objDbDriver->getTables();
        foreach ($arrTables as $arrTable) {
            if ($arrTable["name"] == $strName) {
                return true;
            }
        }

        // create table
        $bitReturn = $this->objDbDriver->createTable($strName, $arrFields, $arrKeys);
        if (!$bitReturn) {
            $this->getError("", array());
        }

        // create index
        if ($bitReturn && count($arrIndices) > 0) {
            foreach ($arrIndices as $strOneIndex) {
                if (is_array($strOneIndex)) {
                    $bitReturn = $bitReturn && $this->createIndex($strName, "ix_".uniqid(), $strOneIndex);
                } else {
                    $bitReturn = $bitReturn && $this->createIndex($strName, "ix_".uniqid(), [$strOneIndex]);
                }
            }
        }

        $this->flushTablesCache();

        return $bitReturn;
    }

    /**
     * @inheritDoc
     */
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
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        if ($this->objDbDriver->hasIndex($strTable, $strName)) {
            return true;
        }
        $bitReturn = $this->objDbDriver->createIndex($strTable, $strName, $arrColumns, $bitUnique);
        if (!$bitReturn) {
            $this->getError("", array());
        }

        return $bitReturn;
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $table, string $index): bool
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        return $this->objDbDriver->deleteIndex($table, $index);
    }

    /**
     * @inheritDoc
     */
    public function addIndex(string $table, TableIndex $index)
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        return $this->objDbDriver->addIndex($table, $index);
    }

    /**
     * @inheritDoc
     */
    public function hasIndex($strTable, $strName): bool
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        return $this->objDbDriver->hasIndex($strTable, $strName);
    }

    /**
     * @inheritDoc
     */
    public function renameTable($strOldName, $strNewName)
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        $return = $this->objDbDriver->renameTable($strOldName, $strNewName);

        $this->flushTablesCache();

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype)
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        $return = $this->objDbDriver->changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype);

        if (!$return) {
            $error = $this->objDbDriver->getError();
            throw new ChangeColumnException('Could not change column: ' . $error, $strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype);
        }

        $this->flushTablesCache();

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function addColumn($strTable, $strColumn, $strDatatype, $bitNull = null, $strDefault = null)
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        if ($this->hasColumn($strTable, $strColumn)) {
            return true;
        }

        $return = $this->objDbDriver->addColumn($strTable, $strColumn, $strDatatype, $bitNull, $strDefault);

        if (!$return) {
            $error = $this->objDbDriver->getError();
            throw new AddColumnException('Could not add column: ' . $error, $strTable, $strColumn, $strDatatype, $bitNull, $strDefault);
        }

        $this->flushTablesCache();

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function removeColumn($strTable, $strColumn)
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        $return = $this->objDbDriver->removeColumn($strTable, $strColumn);

        if (!$return) {
            $error = $this->objDbDriver->getError();
            throw new RemoveColumnException('Could not remove column: ' . $error, $strTable, $strColumn);
        }

        $this->flushTablesCache();

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function hasColumn($strTable, $strColumn)
    {
        $arrColumns = $this->getColumnsOfTable($strTable);
        foreach ($arrColumns as $arrColumn) {
            if (strtolower($arrColumn["columnName"]) == strtolower($strColumn)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function hasTable($strTable)
    {
        return in_array($strTable, $this->getTables());
    }

    /**
     * Parses a query to eliminate unnecessary characters such as whitespaces
     *
     * @param string $strQuery
     *
     * @return string
     */
    private function processQuery($strQuery)
    {

        $strQuery = trim($strQuery);
        $arrSearch = array(
            "\r\n",
            "\n",
            "\r",
            "\t",
            "    ",
            "   ",
            "  "
        );
        $arrReplace = array(
            "",
            "",
            "",
            " ",
            " ",
            " ",
            " "
        );

        $strQuery = str_replace($arrSearch, $arrReplace, $strQuery);

        return $strQuery;
    }

    /**
     * Queries the current db-driver about common information
     *
     * @return mixed|string
     */
    public function getDbInfo()
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        if ($this->objDbDriver != null) {
            return $this->objDbDriver->getDbInfo();
        }

        return "";
    }


    /**
     * Returns the number of queries sent to the database
     * including those solved by the cache
     *
     * @return int
     */
    public function getNumber()
    {
        return $this->intNumber;
    }

    /**
     * Returns the number of queries solved by the cache
     *
     * @return int
     */
    public function getNumberCache()
    {
        return $this->intNumberCache;
    }

    /**
     * Returns the number of items currently in the query-cache
     *
     * @return  int
     */
    public function getCacheSize()
    {
        return count($this->arrQueryCache);
    }

    /**
     * Internal wrapper to dbsafeString, used to process a complete array of parameters
     * as used by prepared statements.
     *
     * @param array $arrParams
     * @param array $arrEscapes An array of boolean for each param, used to block the escaping of html-special chars.
     *                          If not passed, all params will be cleaned.
     *
     * @return array
     * @since 3.4
     * @see Db::dbsafeString($strString, $bitHtmlSpecialChars = true)
     */
    private function dbsafeParams($arrParams, $arrEscapes = array())
    {
        $replace = [];
        foreach ($arrParams as $intKey => $strParam) {

            if ($strParam instanceof EscapeableParameterInterface && !$strParam->isEscape()) {
                $replace[$intKey] = $strParam->getValue();
                continue;
            }

            if (isset($arrEscapes[$intKey])) {
                $strParam = $this->dbsafeString($strParam, $arrEscapes[$intKey], false);
            } else {
                $strParam = $this->dbsafeString($strParam, true, false);
            }
            $replace[$intKey] = $strParam;
        }
        return $replace;
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
        //skip for numeric values to avoid php type juggling/autoboxing
        if (is_float($strString) || is_int($strString)) {
            return $strString;
        } else if (is_bool($strString)) {
            return (int) $strString;
        }

        if ($strString === null) {
            return null;
        }

        if (!self::$bitDbSafeStringEnabled) {
            return $strString;
        }

        //escape special chars
        if ($bitHtmlSpecialChars) {
            $strString = html_entity_decode((string) $strString, ENT_COMPAT, "UTF-8");
            $strString = htmlspecialchars($strString, ENT_COMPAT, "UTF-8");
        }

        if ($bitAddSlashes) {
            $strString = addslashes($strString);
        }

        return $strString;
    }

    /**
     * Method to flush the query-cache
     *
     * @return void
     */
    public function flushQueryCache()
    {
        $this->arrQueryCache = array();
    }

    /**
     * Method to flush the table-cache.
     * Since the tables won't change during regular operations,
     * flushing the tables cache is only required during package updates / installations
     *
     * @return void
     */
    public function flushTablesCache()
    {
        $this->arrTablesCache = [];
    }

    /**
     * Helper to flush the precompiled queries stored at the db-driver.
     * Use this method with great care!
     *
     * @return void
     */
    public function flushPreparedStatementsCache()
    {
        if (!$this->bitConnected) {
            $this->dbconnect();
        }

        $this->objDbDriver->flushQueryCache();
    }

    /**
     * @inheritDoc
     */
    public function encloseColumnName($strColumn)
    {
        return $this->objDbDriver->encloseColumnName($strColumn);
    }

    /**
     * @inheritDoc
     */
    public function encloseTableName($strTable)
    {
        return $this->objDbDriver->encloseTableName($strTable);
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
        try {
            $this->driverFactory->factory($objCfg->getDriver())->dbconnect($objCfg);

            return true;
        } catch (ConnectionException $objEx) {
        } catch (DriverNotFoundException $objEx) {
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getBitConnected()
    {
        return $this->bitConnected;
    }

    /**
     * For some database vendors we need to escape the backslash character even if we are using prepared statements. This
     * method unifies the behaviour. In order to select a column which contains a backslash you need to escape the value
     * with this method
     *
     * @param string $strValue
     *
     * @return mixed
     */
    public function escape($strValue)
    {
        return $this->objDbDriver->escape($strValue);
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
        return $this->objDbDriver->appendLimitExpression($strQuery, $intStart, $intEnd);
    }

    /**
     * @inheritDoc
     */
    public function getConcatExpression(array $parts)
    {
        return $this->objDbDriver->getConcatExpression($parts);
    }

    /**
     * @inheritDoc
     */
    public function convertToDatabaseValue($value, string $type)
    {
        return $this->objDbDriver->convertToDatabaseValue($value, $type);
    }

    /**
     * @inheritDoc
     */
    public function getLeastExpression(array $parts): string
    {
        return $this->objDbDriver->getLeastExpression($parts);
    }
}
