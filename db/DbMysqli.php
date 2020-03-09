<?php

namespace Kajona\System\System\Db;

use Kajona\System\System\Db\Schema\Table;
use Kajona\System\System\Db\Schema\TableColumn;
use Kajona\System\System\Db\Schema\TableIndex;
use Kajona\System\System\Db\Schema\TableKey;
use Kajona\System\System\DbConnectionParams;
use Kajona\System\System\DbDatatypes;
use Kajona\System\System\Exception;
use Kajona\System\System\Logger;
use Kajona\System\System\StringUtil;
use mysqli;
use mysqli_stmt;

/**
 * db-driver for MySQL using the php-mysqli-interface
 *
 * @package module_system
 */
class DbMysqli extends DbBase
{
    /**  @var mysqli */
    private $linkDB; //DB-Link

    /** @var  DbConnectionParams */
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
    public function dbconnect(DbConnectionParams $objParams)
    {
        if ($objParams->getIntPort() == "" || $objParams->getIntPort() == 0) {
            $objParams->setIntPort(3306);
        }

        //save connection-details
        $this->objCfg = $objParams;

        $this->linkDB = @new mysqli(
            $this->objCfg->getStrHost(),
            $this->objCfg->getStrUsername(),
            $this->objCfg->getStrPass(),
            $this->objCfg->getStrDbName(),
            $this->objCfg->getIntPort()
        );

        if ($this->linkDB !== false) {
            if (@$this->linkDB->select_db($this->objCfg->getStrDbName())) {
                //erst ab mysql-client-bib > 4
                //mysqli_set_charset($this->linkDB, "utf8");
                $this->_pQuery("SET NAMES 'utf8'", array());
                $this->_pQuery("SET CHARACTER SET utf8", array());
                $this->_pQuery("SET character_set_connection ='utf8'", array());
                $this->_pQuery("SET character_set_database ='utf8'", array());
                $this->_pQuery("SET character_set_server ='utf8'", array());
                return true;
            } else {
                throw new Exception("Error selecting database", Exception::$level_FATALERROR);
            }
        } else {
            throw new Exception("Error connecting to database", Exception::$level_FATALERROR);
        }
    }

    /**
     * Closes the connection to the database
     *
     * @return void
     */
    public function dbclose()
    {
        $this->linkDB->close();
    }

    /**
     * Sends a prepared statement to the database. All params must be represented by the ? char.
     * The params themself are stored using the second params using the matching order.
     *
     * @param string $strQuery
     * @param array $arrParams
     *
     * @return bool
     * @since 3.4
     */
    public function _pQuery($strQuery, $arrParams)
    {
        $objStatement = $this->getPreparedStatement($strQuery);
        $bitReturn = false;
        if ($objStatement !== false) {
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

            $this->intAffectedRows = $objStatement->affected_rows;
        }

        return $bitReturn;
    }

    /**
     * This method is used to retrieve an array of resultsets from the database using
     * a prepared statement.
     *
     * @param string $strQuery
     * @param array $arrParams
     *
     * @return array|bool
     * @since 3.4
     */
    public function getPArray($strQuery, $arrParams)
    {
        $objStatement = $this->getPreparedStatement($strQuery);
        $arrReturn = array();
        if ($objStatement !== false) {
            $strTypes = "";
            foreach ($arrParams as $strOneParam) {
                $strTypes .= "s";
            }

            if (count($arrParams) > 0) {
                $arrParams = array_merge(array($strTypes), $arrParams);
                call_user_func_array(array($objStatement, 'bind_param'), $this->refValues($arrParams));
            }

            if (!$objStatement->execute()) {
                return false;
            }

            //should remain here due to the bug http://bugs.php.net/bug.php?id=47928
            $objStatement->store_result();

            $objMetadata = $objStatement->result_metadata();
            $arrParams = array();
            $arrRow = array();

            while ($objField = $objMetadata->fetch_field()) {
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
        } else {
            return false;
        }

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
     * Returns the last error reported by the database.
     * Is being called after unsuccessful queries
     *
     * @return string
     */
    public function getError()
    {
        $strError = $this->strErrorMessage . " " . $this->linkDB->error;
        $this->strErrorMessage = "";

        return $strError;
    }

    /**
     * Returns ALL tables in the database currently connected to
     *
     * @return mixed
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
     * Fetches the full table information as retrieved from the rdbms
     *
     * @param $tableName
     * @return Table
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
            return DbDatatypes::STR_TYPE_INT;
        } elseif ($infoSchemaRow["Type"] == "bigint(20)" || $infoSchemaRow["Type"] == "bigint") {
            return DbDatatypes::STR_TYPE_LONG;
        } elseif ($infoSchemaRow["Type"] == "double") {
            return DbDatatypes::STR_TYPE_DOUBLE;
        } elseif ($infoSchemaRow["Type"] == "varchar(10)") {
            return DbDatatypes::STR_TYPE_CHAR10;
        } elseif ($infoSchemaRow["Type"] == "varchar(20)") {
            return DbDatatypes::STR_TYPE_CHAR20;
        } elseif ($infoSchemaRow["Type"] == "varchar(100)") {
            return DbDatatypes::STR_TYPE_CHAR100;
        } elseif ($infoSchemaRow["Type"] == "varchar(254)") {
            return DbDatatypes::STR_TYPE_CHAR254;
        } elseif ($infoSchemaRow["Type"] == "varchar(500)") {
            return DbDatatypes::STR_TYPE_CHAR500;
        } elseif ($infoSchemaRow["Type"] == "text") {
            return DbDatatypes::STR_TYPE_TEXT;
        } elseif ($infoSchemaRow["Type"] == "longtext") {
            return DbDatatypes::STR_TYPE_LONGTEXT;
        }
        return null;
    }

    /**
     * Returns the db-specific datatype for the kajona internal datatype.
     *
     * @param string $strType
     *
     * @return string
     */
    public function getDatatype($strType)
    {
        $strReturn = "";

        if ($strType == DbDatatypes::STR_TYPE_INT) {
            $strReturn .= " INT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_LONG) {
            $strReturn .= " BIGINT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_DOUBLE) {
            $strReturn .= " DOUBLE ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR10) {
            $strReturn .= " VARCHAR( 10 ) ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR20) {
            $strReturn .= " VARCHAR( 20 ) ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR100) {
            $strReturn .= " VARCHAR( 100 ) ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR254) {
            $strReturn .= " VARCHAR( 254 ) ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR500) {
            $strReturn .= " VARCHAR( 500 ) ";
        } elseif ($strType == DbDatatypes::STR_TYPE_TEXT) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_LONGTEXT) {
            $strReturn .= " LONGTEXT ";
        } else {
            $strReturn .= " VARCHAR( 254 ) ";
        }

        return $strReturn;
    }

    /**
     * Used to send a create table statement to the database
     * By passing the query through this method, the driver can
     * add db-specific commands.
     * The array of fields should have the following structure
     * $array[string columnName] = array(string datatype, boolean isNull [, default (only if not null)])
     * whereas datatype is one of the following:
     *         int
     *      long
     *         double
     *         char10
     *         char20
     *         char100
     *         char254
     *      char500
     *         text
     *      longtext
     *
     * @param string $strName
     * @param array $arrFields array of fields / columns
     * @param array $arrKeys array of primary keys
     *
     * @return bool
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
        $strQuery .= " ENGINE = innodb CHARACTER SET utf8 COLLATE utf8_unicode_ci;";

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
     * Starts a transaction
     *
     * @return void
     */
    public function transactionBegin()
    {
        //Autocommit 0 setzten
        $strQuery = "SET AUTOCOMMIT = 0";
        $strQuery2 = "BEGIN";
        $this->_pQuery($strQuery, array());
        $this->_pQuery($strQuery2, array());
    }

    /**
     * Ends a successful operation by Commiting the transaction
     *
     * @return void
     */
    public function transactionCommit()
    {
        $str_pQuery = "COMMIT";
        $str_pQuery2 = "SET AUTOCOMMIT = 1";
        $this->_pQuery($str_pQuery, array());
        $this->_pQuery($str_pQuery2, array());
    }

    /**
     * Ends a non-successful transaction by using a rollback
     *
     * @return void
     */
    public function transactionRollback()
    {
        $strQuery = "ROLLBACK";
        $strQuery2 = "SET AUTOCOMMIT = 1";
        $this->_pQuery($strQuery, array());
        $this->_pQuery($strQuery2, array());
    }

    /**
     * @return array|mixed
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
     * Allows the db-driver to add database-specific surrounding to column-names.
     * E.g. needed by the mysql-drivers
     *
     * @param string $strColumn
     *
     * @return string
     */
    public function encloseColumnName($strColumn)
    {
        return "`" . $strColumn . "`";
    }

    /**
     * Allows the db-driver to add database-specific surrounding to table-names.
     *
     * @param string $strTable
     *
     * @return string
     */
    public function encloseTableName($strTable)
    {
        return "`" . $strTable . "`";
    }


    //--- DUMP & RESTORE ------------------------------------------------------------------------------------

    /**
     * Dumps the current db
     *
     * @param string $strFilename
     * @param array $arrTables
     *
     * @return bool
     */
    public function dbExport(&$strFilename, $arrTables)
    {
        $strFilename = _realpath_ . $strFilename;
        $strTables = implode(" ", $arrTables);
        $strParamPass = "";

        if ($this->objCfg->getStrPass() != "") {
            $strParamPass = " -p\"" . $this->objCfg->getStrPass() . "\"";
        }

        if ($this->handlesDumpCompression()) {
            $strFilename .= ".gz";
            $strCommand = $this->strDumpBin . " -h" . $this->objCfg->getStrHost(
                ) . " -u" . $this->objCfg->getStrUsername() . $strParamPass . " -P" . $this->objCfg->getIntPort(
                ) . " " . $this->objCfg->getStrDbName() . " " . $strTables . " | gzip > \"" . $strFilename . "\"";
        } else {
            $strCommand = $this->strDumpBin . " -h" . $this->objCfg->getStrHost(
                ) . " -u" . $this->objCfg->getStrUsername() . $strParamPass . " -P" . $this->objCfg->getIntPort(
                ) . " " . $this->objCfg->getStrDbName() . " " . $strTables . " > \"" . $strFilename . "\"";
        }
        //Now do a systemfork
        $intTemp = "";
        system($strCommand, $intTemp);
        if ($intTemp == 0) {
            Logger::getInstance(Logger::DBLOG)->info($this->strDumpBin . " exited with code " . $intTemp);
        } else {
            Logger::getInstance(Logger::DBLOG)->warning($this->strDumpBin . " exited with code " . $intTemp);
        }

        return $intTemp == 0;
    }

    /**
     * Imports the given db-dump to the database
     *
     * @param string $strFilename
     *
     * @return bool
     */
    public function dbImport($strFilename)
    {
        $strFilename = _realpath_ . $strFilename;
        $strParamPass = "";

        if ($this->objCfg->getStrPass() != "") {
            $strParamPass = " -p\"" . $this->objCfg->getStrPass() . "\"";
        }

        if ($this->handlesDumpCompression() && StringUtil::endsWith($strFilename, ".gz")) {
            $strCommand = " gunzip -c \"" . $strFilename . "\" | " . $this->strRestoreBin . " -h" . $this->objCfg->getStrHost(
                ) . " -u" . $this->objCfg->getStrUsername() . $strParamPass . " -P" . $this->objCfg->getIntPort(
                ) . " " . $this->objCfg->getStrDbName() . "";
        } else {
            $strCommand = $this->strRestoreBin . " -h" . $this->objCfg->getStrHost(
                ) . " -u" . $this->objCfg->getStrUsername() . $strParamPass . " -P" . $this->objCfg->getIntPort(
                ) . " " . $this->objCfg->getStrDbName() . " < \"" . $strFilename . "\"";
        }
        $intTemp = "";
        system($strCommand, $intTemp);
        if ($intTemp == 0) {
            Logger::getInstance(Logger::DBLOG)->info($this->strDumpBin . " exited with code " . $intTemp);
        } else {
            Logger::getInstance(Logger::DBLOG)->warning($this->strDumpBin . " exited with code " . $intTemp);
        }
        return $intTemp == 0;
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
     * @return mysqli_stmt
     */
    private function getPreparedStatement($strQuery)
    {
        $strName = md5($strQuery);

        if (isset($this->arrStatementsCache[$strName])) {
            return $this->arrStatementsCache[$strName];
        }

        if (count($this->arrStatementsCache) > 300) {
            /** @var mysqli_stmt $objOneEntry */
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
     * @param string $strValue
     *
     * @return mixed
     */
    public function escape($strValue)
    {
        return str_replace("\\", "\\\\", $strValue);
    }

}

