<?php
/*"******************************************************************************************************
*   (c) 2004-2006 by MulchProductions, www.mulchprod.de                                                 *
*   (c) 2007-2016 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/

namespace Kajona\System\System\Db;

use Kajona\System\System\DbConnectionParams;
use Kajona\System\System\DbDatatypes;
use Kajona\System\System\Exception;
use Kajona\System\System\Logger;

/**
 * DbSqlsrv
 *
 * @package module_system
 * @author christoph.kappestein@gmail.com
 * @since 7.0
 */
class DbSqlsrv extends DbBase
{
    /**
     * @var resource
     */
    private $linkDB;

    /**
     * @var DbConnectionParams
     */
    private $objCfg;

    /**
     * @var bool
     */
    private $bitTxOpen = false;

    /**
     * @inheritdoc
     */
    public function dbconnect(DbConnectionParams $objParams)
    {
        if ($objParams->getIntPort() == "" || $objParams->getIntPort() == 0) {
            $objParams->setIntPort(1433);
        }

        $this->objCfg = $objParams;

        // We need to set this to 0 otherwise i.e. the sp_rename procedure returns false with a warning even if the
        // query was successful
        sqlsrv_configure("WarningsReturnAsErrors", 0);

        $this->linkDB = sqlsrv_connect($this->objCfg->getStrHost(), [
            "UID" => $this->objCfg->getStrUsername(),
            "PWD" => $this->objCfg->getStrPass(),
            "Database" => $this->objCfg->getStrDbName(),
            "CharacterSet" => "UTF-8",
        ]);

        if ($this->linkDB === false) {
            throw new Exception("Error connecting to database: ".$this->getError(), Exception::$level_FATALERROR);
        }
    }

    /**
     * Closes the connection to the database
     *
     * @return void
     */
    public function dbclose()
    {
        sqlsrv_close($this->linkDB);
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
        $objStatement = sqlsrv_prepare($this->linkDB, $strQuery, array_values($arrParams));
        if ($objStatement === false) {
            return false;
        }


        $bitResult = sqlsrv_execute($objStatement);

        if (!$bitResult) {
            return false;
        }

        $this->intAffectedRows = sqlsrv_rows_affected($objStatement);

        sqlsrv_free_stmt($objStatement);
        return $bitResult;
    }

    /**
     * This method is used to retrieve an array of resultsets from the database using
     * a prepared statement
     *
     * @param string $strQuery
     * @param array $arrParams
     *
     * @since 3.4
     * @return array
     */
    public function getPArray($strQuery, $arrParams)
    {
        $arrReturn = array();
        $intCounter = 0;

        $objStatement = sqlsrv_query($this->linkDB, $strQuery, $arrParams);

        if ($objStatement === false) {
            return false;
        }

        while ($arrRow = sqlsrv_fetch_array($objStatement, SQLSRV_FETCH_ASSOC)) {
            $arrRow = $this->parseResultRow($arrRow);
            $arrReturn[$intCounter++] = $arrRow;
        }

        sqlsrv_free_stmt($objStatement);

        return $arrReturn;
    }

    /**
     * Returns the last error reported by the database.
     * Is being called after unsuccessful queries
     *
     * @return string
     */
    public function getError()
    {
        $arrErrors = sqlsrv_errors();
        return print_r($arrErrors, true);
    }

    /**
     * Returns ALL tables in the database currently connected to
     *
     * @return mixed
     */
    public function getTables()
    {
        $arrTemp = $this->getPArray("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE'", array());

        foreach ($arrTemp as $intKey => $strValue) {
            $arrTemp[$intKey]["name"] = strtolower($strValue["table_name"]);
        }
        return $arrTemp;
    }

    /**
     * Looks up the columns of the given table.
     * Should return an array for each row consting of:
     * array ("columnName", "columnType")
     *
     * @param string $strTableName
     *
     * @return array
     */
    public function getColumnsOfTable($strTableName)
    {
        $arrReturn = array();
        $arrTemp = $this->getPArray("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?", array(strtoupper($strTableName)));

        if (empty($arrTemp)) {
            return array();
        }

        foreach ($arrTemp as $arrOneColumn) {
            $arrReturn[] = array(
                "columnName" => strtolower($arrOneColumn["column_name"]),
                "columnType" => ($arrOneColumn["data_type"] == "integer" ? "int" : strtolower($arrOneColumn["data_type"])),
            );

        }

        return $arrReturn;
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
            $strReturn .= " INT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_LONG) {
            $strReturn .= " BIGINT ";
        } elseif ($strType == DbDatatypes::STR_TYPE_DOUBLE) {
            $strReturn .= " FLOAT( 24 ) ";
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
            $strReturn .= " VARCHAR( MAX ) ";
        } elseif ($strType == DbDatatypes::STR_TYPE_LONGTEXT) {
            $strReturn .= " VARCHAR( MAX ) ";
        } else {
            $strReturn .= " VARCHAR( 254 ) ";
        }

        return $strReturn;
    }

    /**
     * @inheritdoc
     */
    public function renameTable($strOldName, $strNewName)
    {
        return $this->_pQuery("EXEC sp_rename '{$strOldName}', '{$strNewName}'", array());
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
        if ($strOldColumnName != $strNewColumnName) {
            $bitReturn = $this->_pQuery("EXEC sp_rename '{$strTable}.{$strOldColumnName}', '{$strNewColumnName}', 'COLUMN'", array());
        } else {
            $bitReturn = true;
        }

        return $bitReturn && $this->_pQuery("ALTER TABLE {$strTable} ALTER COLUMN {$strNewColumnName} {$this->getDatatype($strNewDatatype)}", array());
    }

    /**
     * Adds a column to a table
     *
     * @param $strTable
     * @param $strColumn
     * @param $strDatatype
     *
     * @return bool
     * @since 4.6
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
     * Used to send a create table statement to the database
     * By passing the query through this method, the driver can
     * add db-specific commands.
     * The array of fields should have the following structure
     * $array[string columnName] = array(string datatype, boolean isNull [, default (only if not null)])
     * whereas datatype is one of the following:
     *         int
     *         long
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

        //loop over existing tables to check, if the table already exists
        $arrTables = $this->getTables();
        foreach ($arrTables as $arrOneTable) {
            if ($arrOneTable["name"] == $strName) {
                return true;
            }
        }

        //build the oracle code
        $strQuery .= "CREATE TABLE ".$this->encloseTableName($strName)." ( \n";

        //loop the fields
        foreach ($arrFields as $strFieldName => $arrColumnSettings) {
            $strQuery .= " ".$this->encloseColumnName($strFieldName)." ";

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
        $strQuery .= " CONSTRAINT pk_".generateSystemid()." primary key ( ".implode(" , ", $arrKeys)." ) \n";
        $strQuery .= ") ";

        return $this->_pQuery($strQuery, array());
    }

    /**
     * @inheritdoc
     */
    public function createIndex($strTable, $strName, $arrColumns, $bitUnique = false)
    {
        return $this->_pQuery("CREATE ".($bitUnique ? "UNIQUE" : "")." INDEX ".$strName." ON ".$strTable." (" . implode(",", $arrColumns) . ")", []);
    }

    /**
     * @inheritdoc
     */
    public function hasIndex($strTable, $strName)
    {
        $strQuery = "SELECT name FROM sys.indexes WHERE name = ? AND object_id = OBJECT_ID(?)";

        $arrIndex = $this->getPArray($strQuery, [$strName, $strTable]);
        return count($arrIndex) > 0;
    }

    /**
     * @inheritDoc
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns)
    {
        $arrPlaceholder = array();
        $arrMappedColumns = array();
        $arrKeyValuePairs = array();
        $arrParams = [];

        foreach ($arrColumns as $intKey => $strOneCol) {
            $arrPlaceholder[] = "?";
            $arrMappedColumns[] = $this->encloseColumnName($strOneCol);
            $arrKeyValuePairs[] = $this->encloseColumnName($strOneCol)." = ?";


            if (in_array($strOneCol, $arrPrimaryColumns)) {
                $arrPrimaryCompares[] = $strOneCol." = ? ";
                $arrParams[] = $arrValues[$intKey];
            }
        }

        $arrParams = array_merge($arrParams, $arrValues, $arrValues, $arrParams);

        $strQuery = "
            IF NOT EXISTS (SELECT ".implode(",", $arrPrimaryColumns)." FROM ".$this->encloseTableName($strTable)." WHERE ".implode(" AND ", $arrPrimaryCompares).")
                INSERT INTO ".$this->encloseTableName($strTable)." (".implode(", ", $arrMappedColumns).") 
                     VALUES (".implode(", ", $arrPlaceholder).")
            ELSE
                UPDATE ".$this->encloseTableName($strTable)." SET " . implode(", ", $arrKeyValuePairs) . "
                 WHERE ".implode(" AND ", $arrPrimaryCompares);

        return $this->_pQuery($strQuery, $arrParams);
    }

    /**
     * Starts a transaction
     *
     * @return void
     */
    public function transactionBegin()
    {
        sqlsrv_begin_transaction($this->linkDB);
        $this->bitTxOpen = true;
    }

    /**
     * Ends a successful operation by committing the transaction
     *
     * @return void
     */
    public function transactionCommit()
    {
        sqlsrv_commit($this->linkDB);
        $this->bitTxOpen = false;
    }

    /**
     * Ends a non-successful transaction by using a rollback
     *
     * @return void
     */
    public function transactionRollback()
    {
        sqlsrv_rollback($this->linkDB);
        $this->bitTxOpen = false;
    }

    /**
     * @return array|mixed
     */
    public function getDbInfo()
    {
        return sqlsrv_server_info($this->linkDB);
    }


    //--- DUMP & RESTORE ------------------------------------------------------------------------------------


    /**
     * @inheritdoc
     */
    public function handlesDumpCompression()
    {
        return false;
    }

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
        // @TODO implement
        return false;
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
        // @TODO implement
        return false;
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

        return $arrRow;
    }

    /**
     * @inheritdoc
     */
    public function appendLimitExpression($strQuery, $intStart, $intEnd)
    {
        $intLength = $intEnd - $intStart + 1;

        // OFFSET and FETCH can only be used with an ORDER BY
        if (!$this->containsOrderBy($strQuery)) {
            // should be fixed but produces a file write on every call so its bad for the performance
            //Logger::getInstance(Logger::DBLOG)->warning("Using a limit expression without an order by: {$strQuery}");

            $strQuery .= " ORDER BY 1 ASC ";
        }

        return $strQuery . " OFFSET {$intStart} ROWS FETCH NEXT {$intLength} ROWS ONLY";
    }

    /**
     * @param string $strQuery
     * @return bool
     */
    private function containsOrderBy($strQuery)
    {
        $intPos = stripos($strQuery, "ORDER BY");
        if ($intPos === false) {
            return false;
        } else {
            // here is now the most fucked up heuristic to detect whether we have an ORDER BY in the outer query and
            // not in a sub query
            $intLastPos = strrpos($strQuery, ')');

            if ($intLastPos !== false) {
                // in case the order by is after the closing brace we have an order by otherwise it is used in a sub
                // query
                return $intPos > $intLastPos;
            } else {
                return true;
            }
        }
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
        return '"'.$strColumn.'"';
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
        return '"'.$strTable.'"';
    }
}
