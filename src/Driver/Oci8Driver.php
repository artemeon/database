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
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * db-driver for oracle using the ovi8-interface
 *
 * @package module_system
 * @author sidler@mulchprod.de
 * @since 3.4.1
 */
class Oci8Driver extends DriverAbstract
{

    private $linkDB; //DB-Link
    /** @var ConnectionParameters  */
    private $objCfg = null;

    private $strDumpBin = "exp"; // Binary to dump db (if not in path, add the path here)
    // /usr/lib/oracle/xe/app/oracle/product/10.2.0/server/bin/
    private $strRestoreBin = "imp"; //Binary to restore db (if not in path, add the path here)

    private $bitTxOpen = false;

    private $objErrorStmt = null;

    /**
     * Flag whether the sring comparison method (case sensitive / insensitive) should be reset back to default after the current query
     *
     * @var bool
     */
    private $bitResetOrder = false;

    /**
     * @inheritdoc
     */
    public function dbconnect(ConnectionParameters $objParams)
    {
        $port = $objParams->getPort();
        if (empty($port)) {
            $port = 1521;
        }
        $this->objCfg = $objParams;

        //try to set the NLS_LANG env attribute
        putenv("NLS_LANG=American_America.UTF8");

        $this->linkDB = oci_pconnect($this->objCfg->getUsername(), $this->objCfg->getPassword(), $this->objCfg->getHost().":".$port."/".$this->objCfg->getDatabase(), "AL32UTF8");

        if ($this->linkDB !== false) {
            oci_set_client_info($this->linkDB, "ARTEMEON AGP");
            oci_set_client_identifier($this->linkDB, "ARTEMEON AGP");
            $this->_pQuery("ALTER SESSION SET NLS_NUMERIC_CHARACTERS='.,'", []);
            $this->_pQuery("ALTER SESSION SET MAX_STRING_SIZE=EXTENDED", []);
            $this->_pQuery("ALTER SESSION SET DEFAULT_COLLATION=BINARY_CI", []);
            return true;
        }

        throw new ConnectionException("Error connecting to database");
    }

    /**
     * @inheritDoc
     */
    public function dbclose()
    {
        //do n.th. to keep the persistent connection
        //oci_close($this->linkDB);
    }

    /**
     * @inheritDoc
     */
    public function triggerMultiInsert($strTable, $arrColumns, $arrValueSets, ConnectionInterface $objDb, ?array $arrEscapes): bool
    {
        $safeColumns = array_map(function ($column) { return $this->encloseColumnName($column); }, $arrColumns);
        $paramsPlaceholder = '(' . implode(',', array_fill(0, count($safeColumns), '?')) . ')';
        $columnNames = ' (' . implode(',', $safeColumns) . ') ';

        $params = [];
        $escapeValues = [];
        $insertStatement = 'INSERT ALL ';
        foreach ($arrValueSets as $valueSet) {
            $params[] = array_values($valueSet);
            if ($arrEscapes !== null) {
                $escapeValues[] = $arrEscapes;
            }
            $insertStatement .= ' INTO ' . $this->encloseTableName($strTable) . ' ' . $columnNames . ' VALUES ' . $paramsPlaceholder . ' ';
        }
        $insertStatement .= ' SELECT * FROM dual';

        return $objDb->_pQuery($insertStatement, array_merge(...$params), $escapeValues !== [] ? array_merge(...$escapeValues) : []);
    }

    /**
     * @inheritDoc
     */
    public function _pQuery($strQuery, $arrParams)
    {
        $strQuery = $this->processQuery($strQuery);
        $objStatement = $this->getParsedStatement($strQuery);
        if ($objStatement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        foreach ($arrParams as $intPos => $strValue) {
            if (!oci_bind_by_name($objStatement, ":".($intPos + 1), $arrParams[$intPos])) {
                //echo "oci_bind_by_name failed to bind at pos >".$intPos."<, \n value: ".$strValue."\nquery: ".$strQuery;
                return false;
            }
        }

        $bitAddon = \OCI_COMMIT_ON_SUCCESS;
        if ($this->bitTxOpen) {
            $bitAddon = \OCI_NO_AUTO_COMMIT;
        }
        $bitResult = oci_execute($objStatement, $bitAddon);

        if (!$bitResult) {
            $this->objErrorStmt = $objStatement;
            throw new QueryException('Could not execute statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        $this->intAffectedRows = oci_num_rows($objStatement);

        oci_free_statement($objStatement);
        return $bitResult;
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns)
    {

        //return parent::insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns);

        $arrPlaceholder = array();
        $arrMappedColumns = array();
        $arrKeyValuePairs = array();


        $arrParams = array();
        $arrPrimaryCompares = array();

        foreach ($arrColumns as $intKey => $strOneCol) {
            $arrPlaceholder[] = "?";
            $arrMappedColumns[] = $this->encloseColumnName($strOneCol);

            if (in_array($strOneCol, $arrPrimaryColumns)) {
                $arrPrimaryCompares[] = $strOneCol." = ? ";
                $arrParams[] = $arrValues[$intKey];
            }
        }

        $arrParams = array_merge($arrParams, $arrValues);


        foreach ($arrColumns as $intKey => $strOneCol) {
            if (!in_array($strOneCol, $arrPrimaryColumns)) {
                $arrKeyValuePairs[] = $this->encloseColumnName($strOneCol)." = ?";
                $arrParams[] = $arrValues[$intKey];
            }
        }

        if (empty($arrKeyValuePairs)) {
            $strQuery = "MERGE INTO ".$this->encloseTableName($strTable)." using dual on (".implode(" AND ", $arrPrimaryCompares).") 
                       WHEN NOT MATCHED THEN INSERT (".implode(", ", $arrMappedColumns).") values (".implode(", ", $arrPlaceholder).")";
        } else {
            $strQuery = "MERGE INTO ".$this->encloseTableName($strTable)." using dual on (".implode(" AND ", $arrPrimaryCompares).") 
                       WHEN NOT MATCHED THEN INSERT (".implode(", ", $arrMappedColumns).") values (".implode(", ", $arrPlaceholder).")
                       WHEN MATCHED then update set ".implode(", ", $arrKeyValuePairs)."";
        }
        return $this->_pQuery($strQuery, $arrParams);
    }

    /**
     * @inheritDoc
     */
    public function getPArray($strQuery, $arrParams)
    {
        $arrReturn = array();
        $intCounter = 0;

        $strQuery = $this->processQuery($strQuery, $arrParams);
        $objStatement = $this->getParsedStatement($strQuery);

        if ($objStatement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        $index = 0;
        foreach ($arrParams as $intPos => $strValue) {
            oci_bind_by_name($objStatement, ":".(++$index), $arrParams[$intPos]);
        }

        $bitAddon = OCI_COMMIT_ON_SUCCESS;
        if ($this->bitTxOpen) {
            $bitAddon = OCI_NO_AUTO_COMMIT;
        }

        oci_set_prefetch($objStatement, 300);
        $resultSet = oci_execute($objStatement, $bitAddon);

        if (!$resultSet) {
            $this->objErrorStmt = $objStatement;
            throw new QueryException('Could not execute statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        //this was the old way, we're now no longer loading LOBS by default
        //while ($arrRow = oci_fetch_array($objStatement, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) {
        while ($arrRow = oci_fetch_assoc($objStatement)) {
            $arrRow = $this->parseResultRow($arrRow);
            $arrReturn[$intCounter++] = $arrRow;
        }
        oci_free_statement($objStatement);

        if ($this->bitResetOrder) {
            $this->setCaseSensitiveSort();
            $this->bitResetOrder = false;
        }

        return $arrReturn;
    }

    /**
     * @inheritDoc
     */
    public function getError()
    {
        $strError = oci_error($this->objErrorStmt != null ? $this->objErrorStmt : $this->linkDB);
        $this->objErrorStmt = null;
        return print_r($strError, true);
    }

    /**
     * @inheritDoc
     */
    public function getTables()
    {
        $arrTemp = $this->getPArray("SELECT table_name AS name FROM ALL_TABLES WHERE owner = ?", [$this->objCfg->getUsername()]);

        foreach ($arrTemp as $intKey => $strValue) {
            $arrTemp[$intKey]["name"] = strtolower($strValue["name"]);
        }
        return $arrTemp;
    }

    /**
     * @inheritDoc
     */
    public function getTableInformation(string $tableName): Table
    {
        $table = new Table($tableName);

        $tableName = strtoupper($tableName);

        //fetch all columns
        $columnInfo = $this->getPArray("SELECT * FROM user_tab_columns WHERE table_name = ?", [$tableName]) ?: [];
        foreach ($columnInfo as $arrOneColumn) {
            $col = new TableColumn(strtolower($arrOneColumn["column_name"]));
            $col->setInternalType($this->getCoreTypeForDbType($arrOneColumn));
            $col->setDatabaseType($this->getDatatype($col->getInternalType()));
            $col->setNullable($arrOneColumn["nullable"] == "Y");
            $table->addColumn($col);
        }

        //fetch all indexes
        $indexes = $this->getPArray("
            select b.uniqueness, a.index_name, a.table_name, a.column_name
            from all_ind_columns a, all_indexes b
            where a.index_name=b.index_name
              and a.table_name = ?
            order by a.index_name, a.column_position", [$tableName]) ?: [];
        $indexAggr = [];
        foreach ($indexes as $indexInfo) {
            $indexAggr[$indexInfo["index_name"]] = $indexAggr[$indexInfo["index_name"]] ?? [];
            $indexAggr[$indexInfo["index_name"]][] = $indexInfo["column_name"];
        }
        foreach ($indexAggr as $key => $desc) {
            $index = new TableIndex(strtolower($key));
            $index->setDescription(implode(", ", $desc));
            $table->addIndex($index);
        }

        //fetch all keys
        $keys = $this->getPArray("SELECT cols.table_name, cols.column_name, cols.position, cons.status, cons.owner 
            FROM all_constraints cons, all_cons_columns cols
            WHERE cols.table_name = ?
              AND cons.constraint_type = 'P'
              AND cons.constraint_name = cols.constraint_name
              AND cons.owner = cols.owner
          ", [$tableName]) ?: [];
        foreach ($keys as $keyInfo) {
            $key = new TableKey(strtolower($keyInfo['column_name']));
            $table->addPrimaryKey($key);
        }


        return $table;
    }


    /**
     * Tries to convert a column provided by the database back to the Kajona internal type constant
     * @param $infoSchemaRow
     * @return null|string
     */
    private function getCoreTypeForDbType($infoSchemaRow)
    {
        if ($infoSchemaRow["data_type"] == "NUMBER" && $infoSchemaRow["data_precision"] == 19) {
            return DataType::STR_TYPE_LONG;
        } elseif ($infoSchemaRow["data_type"] == "NUMBER" && $infoSchemaRow["data_precision"] == 19) {
            return DataType::STR_TYPE_LONG;
        } elseif ($infoSchemaRow["data_type"] == "FLOAT" && $infoSchemaRow["data_precision"] == 24) {
            return DataType::STR_TYPE_DOUBLE;
        } elseif ($infoSchemaRow["data_type"] == "VARCHAR2") {
            if ($infoSchemaRow["data_length"] == "10") {
                return DataType::STR_TYPE_CHAR10;
            } elseif ($infoSchemaRow["data_length"] == "20") {
                return DataType::STR_TYPE_CHAR20;
            } elseif ($infoSchemaRow["data_length"] == "100") {
                return DataType::STR_TYPE_CHAR100;
            } elseif ($infoSchemaRow["data_length"] == "254") {
                return DataType::STR_TYPE_CHAR254;
            } elseif ($infoSchemaRow["data_length"] == "280") {
                return DataType::STR_TYPE_CHAR254;
            } elseif ($infoSchemaRow["data_length"] == "500") {
                return DataType::STR_TYPE_CHAR500;
            } elseif ($infoSchemaRow["data_length"] == "4000") {
                return DataType::STR_TYPE_TEXT;
            } elseif ($infoSchemaRow["data_length"] == "32767") {
                return DataType::STR_TYPE_TEXT;
            }
        } elseif ($infoSchemaRow["data_type"] == "CLOB") {
            return DataType::STR_TYPE_LONGTEXT;
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getDatatype($strType)
    {
        $strReturn = "";

        if ($strType == DataType::STR_TYPE_INT) {
            $strReturn .= " NUMBER(19, 0) ";
        } elseif ($strType == DataType::STR_TYPE_LONG) {
            $strReturn .= " NUMBER(19, 0) ";
        } elseif ($strType == DataType::STR_TYPE_DOUBLE) {
            $strReturn .= " FLOAT (24) ";
        } elseif ($strType == DataType::STR_TYPE_CHAR10) {
            $strReturn .= " VARCHAR2( 10 ) ";
        } elseif ($strType == DataType::STR_TYPE_CHAR20) {
            $strReturn .= " VARCHAR2( 20 ) ";
        } elseif ($strType == DataType::STR_TYPE_CHAR100) {
            $strReturn .= " VARCHAR2( 100 ) ";
        } elseif ($strType == DataType::STR_TYPE_CHAR254) {
            $strReturn .= " VARCHAR2( 280 ) ";
        } elseif ($strType == DataType::STR_TYPE_CHAR500) {
            $strReturn .= " VARCHAR2( 500 ) ";
        } elseif ($strType == DataType::STR_TYPE_TEXT) {
            $strReturn .= " VARCHAR2( 32767 ) ";
        } elseif ($strType == DataType::STR_TYPE_LONGTEXT) {
            $strReturn .= " CLOB ";
        } else {
            $strReturn .= " VARCHAR2( 280 ) ";
        }

        return $strReturn;
    }

    /**
     * @inheritDoc
     */
    public function changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype)
    {
        if ($strOldColumnName != $strNewColumnName) {
            $bitReturn = $this->_pQuery("ALTER TABLE ".($this->encloseTableName($strTable))." RENAME COLUMN ".($this->encloseColumnName($strOldColumnName)." TO ".$this->encloseColumnName($strNewColumnName)), array());
        } else {
            $bitReturn = true;
        }

        return $bitReturn && $this->_pQuery("ALTER TABLE ".$this->encloseTableName($strTable)." MODIFY ( ".$this->encloseColumnName($strNewColumnName)." ".$this->getDatatype($strNewDatatype)." )", array());
    }

    /**
     * @inheritDoc
     */
    public function addColumn($strTable, $strColumn, $strDatatype, $bitNull = null, $strDefault = null)
    {
        $strQuery = "ALTER TABLE ".($this->encloseTableName($strTable))." ADD ".($this->encloseColumnName($strColumn)." ".$this->getDatatype($strDatatype));

        if ($strDefault !== null) {
            $strQuery .= " DEFAULT ".$strDefault;
        }

        if ($bitNull !== null) {
            $strQuery .= $bitNull ? " NULL" : " NOT NULL";
        }

        return $this->_pQuery($strQuery, array());
    }

    /**
     * @inheritDoc
     */
    public function createTable($strName, $arrFields, $arrKeys)
    {
        $strQuery = "";

        //build the oracle code
        $strQuery .= "CREATE TABLE ".$strName." ( \n";

        //loop the fields
        foreach ($arrFields as $strFieldName => $arrColumnSettings) {
            $strQuery .= " ".$strFieldName." ";

            $strQuery .= $this->getDatatype($arrColumnSettings[0]);

            //any default?
            if (isset($arrColumnSettings[2])) {
                $strQuery .= "DEFAULT ".$arrColumnSettings[2]." ";
            }

            //nullable?
            if ($arrColumnSettings[1] === true) {
                $strQuery .= " NULL ";
            } else {
                $strQuery .= " NOT NULL ";
            }

            $strQuery .= " , \n";

        }

        //primary keys
        $strQuery .= " CONSTRAINT pk_".uniqid()." primary key ( ".implode(" , ", $arrKeys)." ) \n";
        $strQuery .= ") ";
        $strQuery .= "DEFAULT COLLATION BINARY_CI ";

        return $this->_pQuery($strQuery, array());
    }

    /**
     * @inheritdoc
     */
    public function hasIndex($strTable, $strName): bool
    {
        $arrIndex = $this->getPArray("SELECT INDEX_NAME FROM USER_INDEXES WHERE TABLE_NAME = ? AND INDEX_NAME = ?", [strtoupper($strTable), strtoupper($strName)]);
        return count($arrIndex) > 0;
    }

    /**
     * @inheritdoc
     */
    public function hasColumn(string $tableName, string $columnName): bool
    {
        $columnInfo = $this->getPArray("SELECT column_name FROM user_tab_columns WHERE table_name = ? AND column_name = ?", [strtoupper($tableName), strtoupper($columnName)]);
        return !empty($columnInfo);
    }

    /**
     * @inheritDoc
     */
    public function transactionBegin()
    {
        $this->bitTxOpen = true;
    }

    /**
     * @inheritDoc
     */
    public function transactionCommit()
    {
        oci_commit($this->linkDB);
        $this->bitTxOpen = false;
    }

    /**
     * @inheritDoc
     */
    public function transactionRollback()
    {
        oci_rollback($this->linkDB);
        $this->bitTxOpen = false;
    }

    /**
     * @inheritDoc
     */
    public function getDbInfo()
    {
        $arrReturn = array();
        $arrReturn["version"] = $this->getServerVersion();
        $arrReturn["dbserver"] = oci_server_version($this->linkDB);
        $arrReturn["dbclient"] = function_exists("oci_client_version") ? oci_client_version() : "";
        $arrReturn["nls_sort"] = $this->getPArray("select sys_context ('userenv', 'nls_sort') val1 from sys.dual", array())[0]["val1"];
        $arrReturn["nls_comp"] = $this->getPArray("select sys_context ('userenv', 'nls_comp') val1 from sys.dual", array())[0]["val1"];
        return $arrReturn;
    }


    /**
     * parses the version out of the server info string.
     * @see https://github.com/doctrine/dbal/blob/master/lib/Doctrine/DBAL/Driver/OCI8/OCI8Connection.php
     * @return string
     */
    private function getServerVersion()
    {
        if (! preg_match('/\s+(\d+\.\d+\.\d+\.\d+\.\d+)\s+/', oci_server_version($this->linkDB), $version)) {
            throw new \UnexpectedValueException(oci_server_version($this->linkDB));
        }
        return $version[1];
    }

    /**
     * @inheritdoc
     */
    public function handlesDumpCompression()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function dbExport(&$strFilename, $arrTables)
    {
        $strTables = implode(",", $arrTables);

        $dumpBin = (new ExecutableFinder())->find($this->strDumpBin);
        $strCommand = $dumpBin." ".$this->objCfg->getUsername()."/".$this->objCfg->getPassword()." CONSISTENT=n TABLES=".$strTables." FILE='".$strFilename."'";

        $this->runCommand($strCommand);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function dbImport($strFilename)
    {
        $restoreBin = (new ExecutableFinder())->find($this->strRestoreBin);
        $strCommand = $restoreBin." ".$this->objCfg->getUsername()."/".$this->objCfg->getPassword()." FILE='".$strFilename."'";

        $this->runCommand($strCommand);

        return true;
    }

    /**
     * Transforms the prepared statement into a valid oracle syntax.
     * This is done by replying the ?-chars by :x entries.
     *
     * @param string $strQuery
     *
     * @return string
     */
    private function processQuery($strQuery, $params = null)
    {
        $strQuery = preg_replace_callback('/\?/', static function($value): string {
            static $i = 0;
            $i++;
            return ':' . $i;
        }, $strQuery);

        return $strQuery;
    }

    /**
     * Does as cache-lookup for prepared statements.
     * Reduces the number of recompiles at the db-side.
     *
     * @param string $strQuery
     *
     * @return resource
     * @since 3.4
     */
    private function getParsedStatement($strQuery)
    {

        if (stripos($strQuery, "select") !== false) {
            $strQuery = str_replace(array(" as ", " AS "), array(" ", " "), $strQuery);
        }

        $objStatement = oci_parse($this->linkDB, $strQuery);
        return $objStatement;
    }

    /**
     * converts a result-row. changes all keys to lower-case keys again
     *
     * @param array $arrRow
     * @return array
     */
    private function parseResultRow(array $arrRow)
    {
        $arrRow = array_change_key_case($arrRow, CASE_LOWER);
        if (isset($arrRow["count(*)"])) {
            $arrRow["COUNT(*)"] = $arrRow["count(*)"];
        }

        foreach ($arrRow as $key => $val) {
            if (is_object($val) && get_class($val) == "OCI-Lob") {
                //inject an anonymous lazy loader
                $arrRow[$key] = new class($val)   {
                    private $val;

                    public function __construct($val)
                    {
                        $this->val = $val;
                    }

                    public function __toString()
                    {
                        return (string)$this->val->load();
                    }
                };
            }
        }

        return $arrRow;
    }

    /**
     * @inheritDoc
     */
    public function flushQueryCache()
    {
    }


    /** @var bool caching the version parse & compare  */
    private static $is12c = null;

    /**
     * @inheritdoc
     */
    public function appendLimitExpression($strQuery, $intStart, $intEnd)
    {

        if (self::$is12c === null) {
            self::$is12c = version_compare($this->getServerVersion(), "12.1", "ge");
        }

        if (self::$is12c) {
            //TODO: 12c has a new offset syntax - lets see if it's really faster
            $intDelta = $intEnd - $intStart + 1;
            return $strQuery . " OFFSET {$intStart} ROWS FETCH NEXT {$intDelta} ROWS ONLY";
        }

        $intStart++;
        $intEnd++;

        return "SELECT * FROM (
                     SELECT a.*, ROWNUM rnum FROM
                        ( ".$strQuery.") a
                     WHERE ROWNUM <= ".$intEnd."
                )
                WHERE rnum >= ".$intStart;
    }

    /**
     * @inheritdoc
     */
    public function getConcatExpression(array $parts)
    {
        return implode(" || ", $parts);
    }

    /**
     * @inheritDoc
     */
    public function convertToDatabaseValue($value, string $type)
    {
        if ($type === DataType::STR_TYPE_TEXT) {
            return mb_substr($value, 0, 4000);
        } else {
            return parent::convertToDatabaseValue($value, $type);
        }
    }

    /**
     * Sets the sorting and comparison of strings to case insensitive
     */
    private function setCaseInsensitiveSort()
    {
        $this->_pQuery("alter session set nls_sort=binary_ci", array());
        $this->_pQuery("alter session set nls_comp=LINGUISTIC", array());
    }

    /**
     * Sets the sorting and comparison of strings to case sensitive
     */
    private function setCaseSensitiveSort()
    {
        $this->_pQuery("alter session set nls_sort=binary", array());
        $this->_pQuery("alter session set nls_comp=ANSI", array());
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

