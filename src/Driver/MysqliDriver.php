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
use mysqli;
use Symfony\Component\Process\ExecutableFinder;

/**
 * db-driver for MySQL using the php-mysqli-interface
 *
 * @package module_system
 */
class MysqliDriver extends DriverAbstract
{
    private $connected = false;

    /**  @var mysqli */
    private $linkDB; //DB-Link

    /** @var  ConnectionParameters */
    private $objCfg;

    /** @var string  */
    private $strDumpBin = "mysqldump"; //Binary to dump db (if not in path, add the path here)

    /** @var string  */
    private $strRestoreBin = "mysql"; //Binary to dump db (if not in path, add the path here)

    /** @var string  */
    private $strErrorMessage = "";

    /**
     * @inheritdoc
     */
    public function dbconnect(ConnectionParameters $objParams)
    {
        if ($this->connected) {
            return true;
        }

        $port = $objParams->getPort();
        if (empty($port)) {
            $port = 3306;
        }

        //save connection-details
        $this->objCfg = $objParams;

        $this->linkDB = new mysqli(
            $this->objCfg->getHost(),
            $this->objCfg->getUsername(),
            $this->objCfg->getPassword(),
            $this->objCfg->getDatabase(),
            $port
        );

        if ($this->linkDB->connect_errno) {
            throw new ConnectionException("Error connecting to database: " . $this->linkDB->connect_error);
        }

        //erst ab mysql-client-bib > 4
        //mysqli_set_charset($this->linkDB, "utf8");
        $this->_pQuery("SET NAMES 'utf8mb4'", array());
        $this->_pQuery("SET CHARACTER SET utf8mb4", array());
        $this->_pQuery("SET character_set_connection ='utf8mb4'", array());
        $this->_pQuery("SET character_set_database ='utf8mb4'", array());
        $this->_pQuery("SET character_set_server ='utf8mb4'", array());

        $this->connected = true;
        return true;
    }

    /**
     * @inheritDoc
     */
    public function dbclose()
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
     */
    public function _pQuery($strQuery, $arrParams)
    {
        $objStatement = $this->getPreparedStatement($strQuery);
        if ($objStatement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        $bitReturn = false;
        $strTypes = "";
        foreach ($arrParams as $strOneParam) {
            if (is_float($strOneParam)) {
                $strTypes .= "d";
            } elseif (is_int($strOneParam)) {
                $strTypes .= "i";
            } else {
                $strTypes .= "s";
            }
        }

        if (count($arrParams) > 0) {
            $arrParams = array_merge(array($strTypes), $arrParams);
            call_user_func_array(array($objStatement, 'bind_param'), $this->refValues($arrParams));
        }

        $intCount = 0;
        while ($intCount < 3) {
            $bitReturn = $objStatement->execute();
            if ($bitReturn === false && $objStatement->errno == 1213) {
                // in case we have a dead lock wait a bit and retry the query
                $intCount++;
                sleep(2);
            } else {
                break;
            }
        }

        if ($bitReturn === false) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        $this->intAffectedRows = $objStatement->affected_rows;

        return $bitReturn;
    }

    /**
     * @inheritDoc
     */
    public function getPArray($strQuery, $arrParams)
    {
        $objStatement = $this->getPreparedStatement($strQuery);
        if ($objStatement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        $arrReturn = array();
        $strTypes = "";
        foreach ($arrParams as $strOneParam) {
            $strTypes .= "s";
        }

        if (count($arrParams) > 0) {
            $arrParams = array_merge(array($strTypes), $arrParams);
            call_user_func_array(array($objStatement, 'bind_param'), $this->refValues($arrParams));
        }

        if (!$objStatement->execute()) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        //should remain here due to the bug http://bugs.php.net/bug.php?id=47928
        $objStatement->store_result();

        $objMetadata = $objStatement->result_metadata();
        $arrParams = array();
        $arrRow = array();

        if ($objMetadata === false) {
            $objStatement->free_result();
            return [];
        }

        while ($objMetadata && $objField = $objMetadata->fetch_field()) {
            $arrParams[] = &$arrRow[$objField->name];
        }

        call_user_func_array(array($objStatement, 'bind_result'), $arrParams);

        while ($objStatement->fetch()) {
            $arrSingleRow = array();
            foreach ($arrRow as $key => $val) {
                $arrSingleRow[$key] = $val;
            }
            $arrReturn[] = $arrSingleRow;
        }

        $objStatement->free_result();

        return $arrReturn;
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns)
    {
        $arrPlaceholder = array();
        $arrMappedColumns = array();
        $arrKeyValuePairs = array();

        foreach ($arrColumns as $strOneCol) {
            $arrPlaceholder[] = "?";
            $arrMappedColumns[] = $this->encloseColumnName($strOneCol);
            $arrKeyValuePairs[] = $this->encloseColumnName($strOneCol) . " = ?";
        }

        $strQuery = "INSERT INTO " . $this->encloseTableName($strTable) . " (" . implode(
                ", ",
                $arrMappedColumns
            ) . ") VALUES (" . implode(", ", $arrPlaceholder) . ")
                        ON DUPLICATE KEY UPDATE " . implode(", ", $arrKeyValuePairs);
        return $this->_pQuery($strQuery, array_merge($arrValues, $arrValues));
    }

    /**
     * @inheritDoc
     */
    public function getError()
    {
        $strError = $this->strErrorMessage . " " . $this->linkDB->error;
        $this->strErrorMessage = "";

        return $strError;
    }

    /**
     * @inheritDoc
     */
    public function getTables()
    {
        $arrTemp = $this->getPArray("SHOW TABLE STATUS", array());
        foreach ($arrTemp as $intKey => $arrOneTemp) {
            $arrTemp[$intKey]["name"] = $arrTemp[$intKey]["Name"];
        }
        return $arrTemp;
    }

    /**
     * @inheritDoc
     */
    public function getTableInformation(string $tableName): Table
    {
        $table = new Table($tableName);

        //fetch all columns
        $columnInfo = $this->getPArray("SHOW COLUMNS FROM {$tableName}", []) ?: [];
        foreach ($columnInfo as $arrOneColumn) {
            $col = new TableColumn($arrOneColumn["Field"]);
            $col->setInternalType($this->getCoreTypeForDbType($arrOneColumn));
            $col->setDatabaseType($this->getDatatype($col->getInternalType()));
            $col->setNullable($arrOneColumn["Null"] == "YES");
            $table->addColumn($col);
        }

        //fetch all indexes
        $indexes = $this->getPArray("SHOW INDEX FROM {$tableName} WHERE Key_name != 'PRIMARY'", []) ?: [];
        $indexAggr = [];
        foreach ($indexes as $indexInfo) {
            $indexAggr[$indexInfo["Key_name"]] = $indexAggr[$indexInfo["Key_name"]] ?? [];
            $indexAggr[$indexInfo["Key_name"]][] = $indexInfo["Column_name"];
        }
        foreach ($indexAggr as $key => $desc) {
            $index = new TableIndex($key);
            $index->setDescription(implode(", ", $desc));
            $table->addIndex($index);
        }

        //fetch all keys
        $keys = $this->getPArray("SHOW KEYS FROM {$tableName} WHERE Key_name = 'PRIMARY'", []) ?: [];
        foreach ($keys as $keyInfo) {
            $key = new TableKey($keyInfo['Column_name']);
            $table->addPrimaryKey($key);
        }

        return $table;
    }

    /**
     * Tries to convert a column provided by the database back to the Kajona internal type constant
     *
     * @param $infoSchemaRow
     * @return null|string
     */
    private function getCoreTypeForDbType($infoSchemaRow)
    {
        if ($infoSchemaRow["Type"] == "int(11)" || $infoSchemaRow["Type"] == "int") {
            return DataType::STR_TYPE_INT;
        } elseif ($infoSchemaRow["Type"] == "bigint(20)" || $infoSchemaRow["Type"] == "bigint") {
            return DataType::STR_TYPE_LONG;
        } elseif ($infoSchemaRow["Type"] == "double") {
            return DataType::STR_TYPE_DOUBLE;
        } elseif ($infoSchemaRow["Type"] == "varchar(10)") {
            return DataType::STR_TYPE_CHAR10;
        } elseif ($infoSchemaRow["Type"] == "varchar(20)") {
            return DataType::STR_TYPE_CHAR20;
        } elseif ($infoSchemaRow["Type"] == "varchar(100)") {
            return DataType::STR_TYPE_CHAR100;
        } elseif ($infoSchemaRow["Type"] == "varchar(254)") {
            return DataType::STR_TYPE_CHAR254;
        } elseif ($infoSchemaRow["Type"] == "varchar(500)") {
            return DataType::STR_TYPE_CHAR500;
        } elseif ($infoSchemaRow["Type"] == "text") {
            return DataType::STR_TYPE_TEXT;
        } elseif ($infoSchemaRow["Type"] == "mediumtext") {
            return DataType::STR_TYPE_TEXT;
        } elseif ($infoSchemaRow["Type"] == "longtext") {
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
            $strReturn .= " INT ";
        } elseif ($strType == DataType::STR_TYPE_LONG) {
            $strReturn .= " BIGINT ";
        } elseif ($strType == DataType::STR_TYPE_DOUBLE) {
            $strReturn .= " DOUBLE ";
        } elseif ($strType == DataType::STR_TYPE_CHAR10) {
            $strReturn .= " VARCHAR( 10 ) ";
        } elseif ($strType == DataType::STR_TYPE_CHAR20) {
            $strReturn .= " VARCHAR( 20 ) ";
        } elseif ($strType == DataType::STR_TYPE_CHAR100) {
            $strReturn .= " VARCHAR( 100 ) ";
        } elseif ($strType == DataType::STR_TYPE_CHAR254) {
            $strReturn .= " VARCHAR( 254 ) ";
        } elseif ($strType == DataType::STR_TYPE_CHAR500) {
            $strReturn .= " VARCHAR( 500 ) ";
        } elseif ($strType == DataType::STR_TYPE_TEXT) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DataType::STR_TYPE_LONGTEXT) {
            $strReturn .= " LONGTEXT ";
        } else {
            $strReturn .= " VARCHAR( 254 ) ";
        }

        return $strReturn;
    }

    /**
     * @inheritDoc
     */
    public function createTable($strName, $arrFields, $arrKeys)
    {
        $strQuery = "";

        //build the mysql code
        $strQuery .= "CREATE TABLE IF NOT EXISTS `" . $strName . "` ( \n";

        //loop the fields
        foreach ($arrFields as $strFieldName => $arrColumnSettings) {
            $strQuery .= " `" . $strFieldName . "` ";

            $strQuery .= $this->getDatatype($arrColumnSettings[0]);

            //any default?
            if (isset($arrColumnSettings[2])) {
                $strQuery .= "DEFAULT " . $arrColumnSettings[2] . " ";
            }

            //nullable?
            if ($arrColumnSettings[1] === true) {
                $strQuery .= " NULL , \n";
            } else {
                $strQuery .= " NOT NULL , \n";
            }
        }

        //primary keys
        $strQuery .= " PRIMARY KEY ( `" . implode("` , `", $arrKeys) . "` ) \n";
        $strQuery .= ") ";
        $strQuery .= " ENGINE = innodb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";

        return $this->_pQuery($strQuery, array());
    }

    /**
     * @inheritdoc
     */
    public function createIndex($strTable, $strName, $arrColumns, $bitUnique = false)
    {
        return $this->_pQuery(
            "ALTER TABLE " . $this->encloseTableName(
                $strTable
            ) . " ADD " . ($bitUnique ? "UNIQUE" : "") . " INDEX " . $strName . " (" . implode(",", $arrColumns) . ")",
            []
        );
    }

    /**
     * @inheritdoc
     */
    public function hasIndex($strTable, $strName): bool
    {
        $arrIndex = $this->getPArray("SHOW INDEX FROM {$strTable} WHERE Key_name = ?", [$strName]);
        return count($arrIndex) > 0;
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $table, string $index): bool
    {
        return $this->_pQuery("DROP INDEX {$index} ON {$table}", []);
    }

    /**
     * @inheritDoc
     */
    public function transactionBegin()
    {
        $this->linkDB->begin_transaction();
    }

    /**
     * @inheritDoc
     */
    public function transactionCommit()
    {
        $this->linkDB->commit();
    }

    /**
     * @inheritDoc
     */
    public function transactionRollback()
    {
        $this->linkDB->rollback();
    }

    /**
     * @inheritDoc
     */
    public function getDbInfo()
    {
        $arrReturn = array();
        $arrReturn["dbserver"] = "MySQL " . $this->linkDB->server_info;
        $arrReturn["server version"] = $this->linkDB->server_version;
        $arrReturn["dbclient"] = $this->linkDB->client_info;
        $arrReturn["client version"] = $this->linkDB->client_version;
        $arrReturn["dbconnection"] = $this->linkDB->host_info;
        $arrReturn["protocol version"] = $this->linkDB->protocol_version;
        $arrReturn["thread id"] = $this->linkDB->thread_id;
        return $arrReturn;
    }

    /**
     * @inheritDoc
     */
    public function encloseColumnName($strColumn)
    {
        return "`" . $strColumn . "`";
    }

    /**
     * @inheritDoc
     */
    public function encloseTableName($strTable)
    {
        return "`" . $strTable . "`";
    }


    //--- DUMP & RESTORE ------------------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function dbExport(&$strFilename, $arrTables)
    {
        $strTables = implode(" ", $arrTables);
        $strParamPass = "";

        if ($this->objCfg->getPassword() != "") {
            $strParamPass = " -p\"" . $this->objCfg->getPassword() . "\"";
        }

        $dumpBin = (new ExecutableFinder())->find($this->strDumpBin);

        if ($this->handlesDumpCompression()) {
            $strFilename .= ".gz";
            $strCommand = $dumpBin . " -h" . $this->objCfg->getHost(
                ) . " -u" . $this->objCfg->getUsername() . $strParamPass . " -P" . $this->objCfg->getPort(
                ) . " " . $this->objCfg->getDatabase() . " " . $strTables . " | gzip > \"" . $strFilename . "\"";
        } else {
            $strCommand = $dumpBin . " -h" . $this->objCfg->getHost(
                ) . " -u" . $this->objCfg->getUsername() . $strParamPass . " -P" . $this->objCfg->getPort(
                ) . " " . $this->objCfg->getDatabase() . " " . $strTables . " > \"" . $strFilename . "\"";
        }

        $this->runCommand($strCommand);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function dbImport($strFilename)
    {
        $strParamPass = "";

        if ($this->objCfg->getPassword() != "") {
            $strParamPass = " -p\"" . $this->objCfg->getPassword() . "\"";
        }

        $restoreBin = (new ExecutableFinder())->find($this->strRestoreBin);

        if ($this->handlesDumpCompression() && pathinfo($strFilename, PATHINFO_EXTENSION) === 'gz') {
            $strCommand = " gunzip -c \"" . $strFilename . "\" | " . $restoreBin . " -h" . $this->objCfg->getHost(
                ) . " -u" . $this->objCfg->getUsername() . $strParamPass . " -P" . $this->objCfg->getPort(
                ) . " " . $this->objCfg->getDatabase() . "";
        } else {
            $strCommand = $restoreBin . " -h" . $this->objCfg->getHost(
                ) . " -u" . $this->objCfg->getUsername() . $strParamPass . " -P" . $this->objCfg->getPort(
                ) . " " . $this->objCfg->getDatabase() . " < \"" . $strFilename . "\"";
        }

        $this->runCommand($strCommand);

        return true;
    }

    /**
     * Converts a simple array into a an array of references.
     * Required for PHP > 5.3
     *
     * @param array $arrValues
     *
     * @return array
     */
    private function refValues($arrValues)
    {
        if (strnatcmp(phpversion(), '5.3') >= 0) { //Reference is required for PHP 5.3+
            $refs = array();
            foreach ($arrValues as $key => $value) {
                $refs[$key] = &$arrValues[$key];
            }
            return $refs;
        }
        return $arrValues;
    }

    /**
     * Prepares a statement or uses an instance from the cache
     *
     * @param string $strQuery
     *
     * @return \mysqli_stmt|false
     */
    private function getPreparedStatement($strQuery)
    {
        $strName = md5($strQuery);

        if (isset($this->arrStatementsCache[$strName])) {
            return $this->arrStatementsCache[$strName];
        }

        if (count($this->arrStatementsCache) > 300) {
            /** @var \mysqli_stmt $objOneEntry */
            foreach ($this->arrStatementsCache as $objOneEntry) {
                $objOneEntry->close();
            }

            $this->arrStatementsCache = array();
        }

        $objStatement = $this->linkDB->stmt_init();
        if (!$objStatement->prepare($strQuery)) {
            $this->strErrorMessage = $objStatement->error;
            return false;
        }

        $this->arrStatementsCache[$strName] = $objStatement;

        return $objStatement;
    }

    /**
     * @inheritDoc
     */
    public function escape($strValue)
    {
        return str_replace("\\", "\\\\", $strValue);
    }

}

