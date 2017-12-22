<?php
/*"******************************************************************************************************
*   (c) 2004-2006 by MulchProductions, www.mulchprod.de                                                 *
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/

namespace Kajona\System\System\Db;

use Kajona\System\System\Database;
use Kajona\System\System\DbConnectionParams;
use Kajona\System\System\DbDatatypes;
use Kajona\System\System\Exception;
use Kajona\System\System\Filesystem;
use Kajona\System\System\StringUtil;
use SQLite3;
use SQLite3Stmt;


/**
 * db-driver for sqlite3 using the php-sqlite3-interface.
 * Based on the sqlite2 driver by phwolfer
 *
 * @since 3.3.0.1
 * @author sidler@mulchprod.de
 * @package module_system
 */
class DbSqlite3 extends DbBase
{

    /**
     * @var SQLite3
     */
    private $linkDB;
    private $strDbFile;

    /**
     * @inheritdoc
     */
    public function dbconnect(DbConnectionParams $objParams)
    {
        if ($objParams->getStrDbName() == "") {
            return false;
        }

        $this->strDbFile = _projectpath_.'/dbdumps/'.$objParams->getStrDbName().'.db3';

        try {
            $strPath = _realpath_.$this->strDbFile;
            $this->linkDB = new SQLite3($strPath);
            $this->_pQuery('PRAGMA encoding = "UTF-8"', array());
            //TODO deprecated in sqlite, so may be removed
            $this->_pQuery('PRAGMA short_column_names = ON', array());
            $this->_pQuery("PRAGMA journal_mode = MEMORY", array());
            $this->_pQuery("PRAGMA temp_store = MEMORY", array());
            if (method_exists($this->linkDB, "busyTimeout")) {
                $this->linkDB->busyTimeout(5000);
            }

            return true;
        }
        catch (Exception $e) {
            throw new Exception("Error connecting to database: ".$e, Exception::$level_FATALERROR);
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


    private function buildAndCopyTempTables($strTargetTableName, $arrSourceTableInfo, $arrTargetTableInfo)
    {
        $bitReturn = true;

        /* Get existing table info */
        $arrPragmaTableInfo = $this->getPArray("PRAGMA table_info('{$strTargetTableName}')", array());
        $arrColumnsPragma = array();
        foreach ($arrPragmaTableInfo as $arrRow) {
            $arrColumnsPragma[$arrRow['name']] = $arrRow;
        }

        $arrSourceColumns = array();
        array_walk($arrSourceTableInfo, function ($arrValue) use (&$arrSourceColumns) {
            $arrSourceColumns[] = $arrValue["columnName"];
        });

        $arrTargetColumns = array();
        array_walk($arrTargetTableInfo, function ($arrValue) use (&$arrTargetColumns) {
            $arrTargetColumns[] = $arrValue["columnName"];
        });


        //build the a temp table
        $strQuery = "CREATE TABLE ".$strTargetTableName."_temp ( \n";

        //loop the fields
        $arrColumns = array();
        $arrPks = array();
        foreach ($arrTargetTableInfo as $arrOneColumn) {
            $arrRow = null;

            if (array_key_exists($arrOneColumn["columnName"], $arrColumnsPragma)) {
                $arrRow = $arrColumnsPragma[$arrOneColumn["columnName"]];
            } else {
                $arrRow["name"] = $arrOneColumn["columnName"];
                $arrRow["type"] = $arrOneColumn["columnType"];
            }

            //column settings
            $strColumn = " ".$arrRow["name"]." ".$arrRow["type"];

            if (array_key_exists("notnull", $arrRow) && $arrRow["notnull"] === 1) {
                $strColumn .= " NOT NULL ";
            } elseif (array_key_exists("notnull", $arrRow) && $arrRow["notnull"] === 0) {
                $strColumn .= " NULL ";
            }

            if (array_key_exists("dflt_value", $arrRow) && $arrRow["dflt_value"] !== null) {
                $strColumn .= " DEFAULT {$arrRow["dflt_value"]} ";
            }
            $arrColumns[] = $strColumn;

            //primary key?
            if (array_key_exists("pk", $arrRow) && $arrRow["pk"] === 1) {
                $arrPks[] = $arrRow["name"];
            }
        }

        //columns
        $strQuery .= implode(",\n", $arrColumns);

        //primary keys
        if (count($arrPks) > 0) {
            $strQuery .= ",PRIMARY KEY (";
            $strQuery .= implode(",", $arrPks);
            $strQuery .= ")\n";
        }

        $strQuery .= ")\n";

        $bitReturn = $bitReturn && $this->_pQuery($strQuery, array());

        //copy all values
        $strQuery = "INSERT INTO ".$strTargetTableName."_temp (".implode(",", $arrTargetColumns).") SELECT ".implode(",", $arrSourceColumns)." FROM ".$strTargetTableName;
        $bitReturn = $bitReturn && $this->_pQuery($strQuery, array());

        $strQuery = "DROP TABLE ".$strTargetTableName;
        $bitReturn = $bitReturn && $this->_pQuery($strQuery, array());

        return $bitReturn && $this->renameTable($strTargetTableName."_temp", $strTargetTableName);
    }


    /**
     * Renames a single column of the table
     *
     * @param $strTable
     * @param $strOldColumnName
     * @param $strNewColumnName
     * @param $strNewDatatype
     *
     * @return bool
     * @since 4.6
     */
    public function changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype)
    {

        $arrTableInfo = $this->getColumnsOfTable($strTable);
        $arrTargetTableInfo = array();
        foreach ($arrTableInfo as $arrOneColumn) {
            if ($arrOneColumn["columnName"] == $strOldColumnName) {
                $arrNewRow = array(
                    "columnName" => $strNewColumnName,
                    "columnType" => $this->getDatatype($strNewDatatype)
                );

                $arrTargetTableInfo[] = $arrNewRow;
            } else {
                $arrTargetTableInfo[] = $arrOneColumn;
            }

        }

        return $this->buildAndCopyTempTables($strTable, $arrTableInfo, $arrTargetTableInfo);
    }


    /**
     * removes a single column from the table
     *
     * @param $strTable
     * @param $strColumn
     *
     * @return bool
     * @since 4.6
     */
    public function removeColumn($strTable, $strColumn)
    {

        $arrTableInfo = $this->getColumnsOfTable($strTable);
        $arrTargetTableInfo = array();
        foreach ($arrTableInfo as $arrOneColumn) {
            if ($arrOneColumn["columnName"] != $strColumn) {
                $arrTargetTableInfo[] = $arrOneColumn;
            }

        }

        return $this->buildAndCopyTempTables($strTable, $arrTargetTableInfo, $arrTargetTableInfo);
    }


    /**
     * Creates a single query in order to insert multiple rows at one time.
     * For most databases, this will create s.th. like
     * INSERT INTO $strTable ($arrColumns) VALUES (?, ?), (?, ?)...
     * Please note that this method is used to create the query itself, based on the Kajona-internal syntax.
     * The query is fired to the database by Database
     *
     * @param string $strTable
     * @param string[] $arrColumns
     * @param array $arrValueSets
     * @param Database $objDb
     *
     * @return bool
     */
    public function triggerMultiInsert($strTable, $arrColumns, $arrValueSets, Database $objDb)
    {

        $arrVersion = SQLite3::version();
        if (version_compare("3.7.11", $arrVersion["versionString"], "<=")) {
            return parent::triggerMultiInsert($strTable, $arrColumns, $arrValueSets, $objDb);
        } else {
            //legacy code
            $arrSafeColumns = array();
            $arrPlaceholder = array();
            foreach ($arrColumns as $strOneColumn) {
                $arrSafeColumns[] = $this->encloseColumnName($strOneColumn);
                $arrPlaceholder[] = "?";
            }

            $arrParams = array();

            $strQuery = "INSERT INTO ".$this->encloseTableName($strTable)."  (".implode(",", $arrSafeColumns).") ";
            for ($intI = 0; $intI < count($arrValueSets); $intI++) {
                $arrTemp = array();
                for ($intK = 0; $intK < count($arrColumns); $intK++) {
                    $arrTemp[] = " ? AS ".$this->encloseColumnName($arrColumns[$intK]);
                }

                if ($intI == 0) {
                    $strQuery .= " SELECT ".implode(", ", $arrTemp);
                } else {
                    $strQuery .= " UNION SELECT ".implode(", ", $arrTemp);
                }

                $arrParams = array_merge($arrParams, array_values($arrValueSets[$intI]));
            }

            return $objDb->_pQuery($strQuery, $arrParams);
        }
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns)
    {
        $arrPlaceholder = array();
        $arrMappedColumns = array();

        foreach ($arrColumns as $strOneCol) {
            $arrPlaceholder[] = "?";
            $arrMappedColumns[] = $this->encloseColumnName($strOneCol);
        }

        $strQuery = "INSERT OR REPLACE INTO ".$this->encloseTableName(_dbprefix_.$strTable)." (".implode(", ", $arrMappedColumns).") VALUES (".implode(", ", $arrPlaceholder).")";
        return $this->_pQuery($strQuery, $arrValues);
    }


    /**
     * Sends a prepared statement to the database. All params must be represented by the ? char.
     * The params themselves are stored using the second params using the matching order.
     *
     * @param string $strQuery
     * @param array $arrParams
     *
     * @return bool
     * @since 3.4
     */
    public function _pQuery($strQuery, $arrParams)
    {
        $strQuery = $this->fixQuoting($strQuery);
        $strQuery = $this->processQuery($strQuery);

        $objStmt = $this->getPreparedStatement($strQuery);
        if ($objStmt === false) {
            return false;
        }
        $intCount = 1;
        foreach ($arrParams as $strOneParam) {
            if ($strOneParam === null) {
                $objStmt->bindValue(':param'.$intCount++, $strOneParam, SQLITE3_NULL);
            }
            //else if(is_double($strOneParam))
            //    $objStmt->bindValue(':param'.$intCount++ , $strOneParam, SQLITE3_FLOAT);
            //else if(is_numeric($strOneParam))
            //    $objStmt->bindValue(':param'.$intCount++ , $strOneParam, SQLITE3_INTEGER);
            else {
                $objStmt->bindValue(':param'.$intCount++, $strOneParam, SQLITE3_TEXT);
            }
        }

        if ($objStmt->execute() === false) {
            return false;
        }

        $this->intAffectedRows = $this->linkDB->changes();

        return true;
    }

    /**
     * This method is used to retrieve an array of resultsets from the database usin
     *
     * a prepared statement
     *
     * @param string $strQuery
     * @param array $arrParams
     *
     * @since 3.4
     * @return array|bool
     */
    public function getPArray($strQuery, $arrParams)
    {
        $strQuery = $this->fixQuoting($strQuery);
        $strQuery = $this->processQuery($strQuery);

        $objStmt = $this->getPreparedStatement($strQuery);
        if ($objStmt === false) {
            return false;
        }

        $intCount = 1;
        foreach ($arrParams as $strOneParam) {
            if ($strOneParam === null) {
                $objStmt->bindValue(':param'.$intCount++, $strOneParam, SQLITE3_NULL);
            }
            //else if(is_double($strOneParam))
            //    $objStmt->bindValue(':param'.$intCount++ , $strOneParam, SQLITE3_FLOAT);
            //else if(is_numeric($strOneParam))
            //    $objStmt->bindValue(':param'.$intCount++ , $strOneParam, SQLITE3_INTEGER);
            else {
                $objStmt->bindValue(':param'.$intCount++, $strOneParam, SQLITE3_TEXT);
            }
        }

        $arrResult = array();
        $objResult = $objStmt->execute();

        if ($objResult === false) {
            return false;
        }

        while ($arrTemp = $objResult->fetchArray(SQLITE3_ASSOC)) {
            $arrResult[] = $arrTemp;
        }

        return $arrResult;
    }


    /**
     * Returns the last error reported by the database.
     * Is being called after unsuccessful queries
     *
     * @return string
     */
    public function getError()
    {
        return $this->linkDB->lastErrorMsg();
    }

    /**
     * Returns ALL tables in the database currently connected to.
     * The method should return an array using the following keys:
     * name => Table name
     *
     * @return array
     */
    public function getTables()
    {
        $arrReturn = array();
        $resultSet = $this->linkDB->query("SELECT name FROM sqlite_master WHERE type='table'");
        while ($arrRow = $resultSet->fetchArray(SQLITE3_ASSOC)) {
            $arrReturn[] = array("name" => $arrRow["name"]);
        }
        return $arrReturn;
    }

    /**
     * Looks up the columns of the given table.
     * Should return an array for each row consisting of:
     * array ("columnName", "columnType")
     *
     * @param string $strTableName
     *
     * @return array
     */
    public function getColumnsOfTable($strTableName)
    {
        $arrTableInfo = $this->getPArray("PRAGMA table_info('{$strTableName}')", array());

        $arrColumns = array();
        foreach ($arrTableInfo as $arrRow) {
            $arrColumns[] = array(
                "columnName" => $arrRow['name'],
                "columnType" => $arrRow['type']
            );
        }

        return $arrColumns;
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
     * @param bool $bitTxSafe Should the table support transactions?
     *
     * @return bool
     */
    public function createTable($strName, $arrFields, $arrKeys, $bitTxSafe = true)
    {
        $strQuery = "";

        //build the mysql code
        $strQuery .= "CREATE TABLE ".$strName." ( \n";

        //loop the fields
        foreach ($arrFields as $strFieldName => $arrColumnSettings) {
            $strQuery .= " ".$strFieldName." ";

            $strQuery .= $this->getDatatype($arrColumnSettings[0]);

            //any default?
            if (isset($arrColumnSettings[2])) {
                $strQuery .= " DEFAULT ".$arrColumnSettings[2]." ";
            }

            //nullable?
            if ($arrColumnSettings[1] === true) {
                $strQuery .= ", \n";
            } else {
                $strQuery .= " NOT NULL, \n";
            }

        }

        //primary keys
        $strQuery .= " PRIMARY KEY (".implode(", ", $arrKeys).") \n";
        $strQuery .= ") ";

        return $this->_pQuery($strQuery, array());
    }

    /**
     * @inheritdoc
     */
    public function hasIndex($strTable, $strName)
    {
        $arrIndex = $this->getPArray("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?", [$strTable, $strName]);
        return count($arrIndex) > 0;
    }

    /**
     * Starts a transaction
     *
     * @return void
     */
    public function transactionBegin()
    {
        $this->_pQuery("BEGIN TRANSACTION", array());
    }

    /**
     * Ends a successful operation by Committing the transaction
     *
     * @return void
     */
    public function transactionCommit()
    {
        $this->_pQuery("COMMIT TRANSACTION", array());
    }

    /**
     * Ends a non-successfull transaction by using a rollback
     *
     * @return void
     */
    public function transactionRollback()
    {
        $this->_pQuery("ROLLBACK TRANSACTION", array());
    }

    /**
     * returns an array with infos about the current database
     * The array returned should have tho following structure:
     * ["dbserver"]
     * ["dbclient"]
     * ["dbconnection"]
     *
     * @return mixed
     */
    public function getDbInfo()
    {
        $arrDB = $this->linkDB->version();
        $arrReturn = array();
        $arrReturn["dbserver"] = "SQLite3 ".$arrDB["versionString"]." ".$arrDB["versionNumber"];
        $arrReturn["location"] = $this->strDbFile;
        $arrReturn["busy timeout"] = $this->getPArray("PRAGMA busy_timeout", array())[0]["timeout"];
        $arrReturn["encoding"] = $this->getPArray("PRAGMA encoding", array())[0]["encoding"];
        return $arrReturn;
    }

    /**
     * @inheritdoc
     */
    public function handlesDumpCompression()
    {
        return false;
    }

    /**
     * Creates an db-dump usind the given filename. the filename is relative to _realpath_
     * The dump must include, and ONLY include the pass tables
     *
     * @param string $strFilename
     * @param array $arrTables
     *
     * @return bool Indicates, if the dump worked or not
     */
    public function dbExport(&$strFilename, $arrTables)
    {
        // FIXME: Only export relevant tables.
        $objFilesystem = new Filesystem();
        return $objFilesystem->fileCopy($this->strDbFile, $strFilename);
    }



    /**
     * Imports the given db-dump file to the database. The filename ist relative to _realpath_
     *
     * @param string $strFilename
     *
     * @return bool
     */
    public function dbImport($strFilename)
    {
        $objFilesystem = new Filesystem();
        return $objFilesystem->fileCopy($strFilename, $this->strDbFile, true);
    }


    /**
     * Returns the db-specific datatype for the kajona internal datatype.
     * Currently, this are
     *      int
     *      long
     *      double
     *      char10
     *      char20
     *      char100
     *      char254
     *      char500
     *      text
     *      longtext
     *
     * @param string $strType
     *
     * @return string
     */
    public function getDatatype($strType)
    {
        $strReturn = "";

        if ($strType == DbDatatypes::STR_TYPE_INT) {
            $strReturn .= " INTEGER ";
        } elseif ($strType == DbDatatypes::STR_TYPE_LONG) {
            $strReturn .= " INTEGER ";
        } elseif ($strType == DbDatatypes::STR_TYPE_DOUBLE) {
            $strReturn .= " REAL ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR10) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR20) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR100) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR254) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_CHAR500) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_TEXT) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_LONGTEXT) {
            $strReturn .= " TEXT ";
        } else {
            $strReturn .= " TEXT ";
        }

        return $strReturn;
    }

    /**
     * Fixes the quoting of ' in queries.
     * By default ' is quoted as \', but it must be quoted as '' in sqlite.
     *
     * @param string $strSql
     *
     * @return string
     */
    private function fixQuoting($strSql)
    {
        $strSql = str_replace("\\'", "''", $strSql);
        $strSql = str_replace("\\\"", "\"", $strSql);
        return $strSql;
    }

    /**
     * Transforms the query into a valid sqlite-syntax
     *
     * @param string $strQuery
     *
     * @return string
     */
    private function processQuery($strQuery)
    {
        $intCount = 1;
        while (StringUtil::indexOf($strQuery, "?") !== false) {
            $intPos = StringUtil::indexOf($strQuery, "?");
            $strQuery = substr($strQuery, 0, $intPos).":param".$intCount++.substr($strQuery, $intPos + 1);
        }
        return $strQuery;
    }

    /**
     * Prepares a statement or uses an instance from the cache
     *
     * @param string $strQuery
     *
     * @return SQLite3Stmt
     */
    private function getPreparedStatement($strQuery)
    {

        $strName = md5($strQuery);

        if (isset($this->arrStatementsCache[$strName])) {
            return $this->arrStatementsCache[$strName];
        }

        $objStmt = $this->linkDB->prepare($strQuery);
        $this->arrStatementsCache[$strName] = $objStmt;

        return $objStmt;
    }

    public function encloseTableName($strTable)
    {
        return "'".$strTable."'";
    }

}

