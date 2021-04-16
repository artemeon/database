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

/**
 * DbSqlsrv
 *
 * @package module_system
 * @author christoph.kappestein@gmail.com
 * @since 7.0
 */
class SqlsrvDriver extends DriverAbstract
{
    /**
     * @var resource
     */
    private $linkDB;

    /**
     * @var ConnectionParameters
     */
    private $objCfg;


    /**
     * @inheritdoc
     */
    public function dbconnect(ConnectionParameters $objParams)
    {
        $this->objCfg = $objParams;

        // We need to set this to 0 otherwise i.e. the sp_rename procedure returns false with a warning even if the
        // query was successful
        sqlsrv_configure("WarningsReturnAsErrors", 0);

        $this->linkDB = sqlsrv_connect($this->objCfg->getHost(), [
            "UID" => $this->objCfg->getUsername(),
            "PWD" => $this->objCfg->getPassword(),
            "Database" => $this->objCfg->getDatabase(),
            "CharacterSet" => "UTF-8",
            "ConnectionPooling" => "1",
            "MultipleActiveResultSets"=> "0",
            "APP" => "Artemeon Core",
            "TransactionIsolation" => SQLSRV_TXN_READ_UNCOMMITTED
        ]);

        if ($this->linkDB === false) {
            throw new ConnectionException("Error connecting to database: ".$this->getError());
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function dbclose()
    {
        //do n.th. to keep the persistent connection
        //sqlsrv_close($this->linkDB);
    }

    /**
     * Internal helper to convert php values to database values
     * currently casting them to strings, otherwise the sqlsrv driver fails to
     * set them back due to type conversions
     * @param $arrParams
     * @return array
     */
    private function convertParamsArray($arrParams)
    {
        $converted = [];
        foreach ($arrParams as $val) {
            //$converted[] = [$val, null, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR)]; //TODO: would be better but not working, casting internally to return type string
            $converted[] = $val === null ? null : $val."";
        }
        return $converted;
    }

    /**
     * @inheritDoc
     */
    public function _pQuery($strQuery, $arrParams)
    {
        $convertParamsArray = $this->convertParamsArray($arrParams);
        $objStatement = sqlsrv_prepare($this->linkDB, $strQuery, $convertParamsArray);
        if ($objStatement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $strQuery, $arrParams);
        }


        $bitResult = sqlsrv_execute($objStatement);
        if (!$bitResult) {
            throw new QueryException('Could not execute statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        $this->intAffectedRows = sqlsrv_rows_affected($objStatement);

        sqlsrv_free_stmt($objStatement);
        return $bitResult;
    }

    /**
     * @inheritDoc
     */
    public function getPArray($strQuery, $arrParams)
    {
        $arrReturn = array();
        $intCounter = 0;

        $objStatement = sqlsrv_query($this->linkDB, $strQuery, $this->convertParamsArray($arrParams));
        if ($objStatement === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        while ($arrRow = sqlsrv_fetch_array($objStatement, SQLSRV_FETCH_ASSOC)) {
            $arrRow = $this->parseResultRow($arrRow);
            $arrReturn[$intCounter++] = $arrRow;
        }

        sqlsrv_free_stmt($objStatement);

        return $arrReturn;
    }

    /**
     * @inheritDoc
     */
    public function getError()
    {
        $arrErrors = sqlsrv_errors();
        return print_r($arrErrors, true);
    }

    /**
     * @inheritDoc
     */
    public function getTables()
    {
        $arrTemp = $this->getPArray("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE'", array()) ?? [];

        foreach ($arrTemp as $intKey => $strValue) {
            $arrTemp[$intKey]["name"] = strtolower($strValue["table_name"]);
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
        $columnInfo = $this->getPArray("SELECT * FROM information_schema.columns WHERE table_name = ?", [$tableName]) ?: [];
        foreach ($columnInfo as $arrOneColumn) {
            $col = new TableColumn($arrOneColumn["column_name"]);
            $col->setInternalType($this->getCoreTypeForDbType($arrOneColumn));
            $col->setDatabaseType($this->getDatatype($col->getInternalType()));
            $col->setNullable($arrOneColumn["is_nullable"] == "YES");
            $table->addColumn($col);
        }

        //fetch all indexes
        $indexes = $this->getPArray("SELECT
                       t.name as tablename,
                       ind.name as indexname,
                       col.name as colname
                FROM
                     sys.indexes ind
                       INNER JOIN
                         sys.index_columns ic ON ind.object_id = ic.object_id and ind.index_id = ic.index_id
                       INNER JOIN
                         sys.columns col ON ic.object_id = col.object_id and ic.column_id = col.column_id
                       INNER JOIN
                         sys.tables t ON ind.object_id = t.object_id
                WHERE
                    ind.is_primary_key = 0
                  AND ind.is_unique = 0
                  AND ind.is_unique_constraint = 0
                  AND t.is_ms_shipped = 0
                  AND t.name = ?
                ORDER BY
                         t.name, ind.name, ind.index_id, ic.index_column_id;", [$tableName]) ?: [];
        $indexAggr = [];
        foreach ($indexes as $indexInfo) {
            $indexAggr[$indexInfo["indexname"]] = $indexAggr[$indexInfo["indexname"]] ?? [];
            $indexAggr[$indexInfo["indexname"]][] = $indexInfo["colname"];
        }
        foreach ($indexAggr as $key => $desc) {
            $index = new TableIndex($key);
            $index->setDescription(implode(", ", $desc));
            $table->addIndex($index);
        }

        //fetch all keys
        $keys = $this->getPArray("SELECT Col.Column_Name 
            from INFORMATION_SCHEMA.TABLE_CONSTRAINTS Tab, INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE Col 
            WHERE Col.Constraint_Name = Tab.Constraint_Name 
              AND Col.Table_Name = Tab.Table_Name 
              AND Constraint_Type = 'PRIMARY KEY' 
              AND Col.Table_Name = ?", [$tableName]) ?: [];
        foreach ($keys as $keyInfo) {
            $key = new TableKey($keyInfo['column_name']);
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
        if ($infoSchemaRow["data_type"] == "int") {
            return DataType::STR_TYPE_INT;
        } elseif ($infoSchemaRow["data_type"] == "bigint") {
            return DataType::STR_TYPE_LONG;
        } elseif ($infoSchemaRow["data_type"] == "real") {
            return DataType::STR_TYPE_DOUBLE;
        } elseif ($infoSchemaRow["data_type"] == "varchar") {
            if ($infoSchemaRow["character_maximum_length"] == "10") {
                return DataType::STR_TYPE_CHAR10;
            } elseif ($infoSchemaRow["character_maximum_length"] == "20") {
                return DataType::STR_TYPE_CHAR20;
            } elseif ($infoSchemaRow["character_maximum_length"] == "100") {
                return DataType::STR_TYPE_CHAR100;
            } elseif ($infoSchemaRow["character_maximum_length"] == "254") {
                return DataType::STR_TYPE_CHAR254;
            } elseif ($infoSchemaRow["character_maximum_length"] == "500") {
                return DataType::STR_TYPE_CHAR500;
            } elseif ($infoSchemaRow["character_maximum_length"] == "-1") {
                return DataType::STR_TYPE_TEXT;
            }
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
            $strReturn .= " FLOAT( 24 ) ";
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
            $strReturn .= " VARCHAR( MAX ) ";
        } elseif ($strType == DataType::STR_TYPE_LONGTEXT) {
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
        return $this->_pQuery("EXEC sp_rename " . $this->encloseTableName($strOldName) . ", " . $this->encloseTableName($strNewName), []);
    }

    /**
     * @inheritDoc
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
        $strQuery .= " CONSTRAINT pk_".uniqid()." primary key ( ".implode(" , ", $arrKeys)." ) \n";
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
     * @inheritDoc
     */
    public function deleteIndex(string $table, string $index): bool
    {
        return $this->_pQuery("DROP INDEX {$table}.{$index}", []);
    }

    /**
     * @inheritdoc
     */
    public function hasIndex($strTable, $strName): bool
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
     * @inheritDoc
     */
    public function transactionBegin()
    {
        sqlsrv_begin_transaction($this->linkDB);
    }

    /**
     * @inheritDoc
     */
    public function transactionCommit()
    {
        sqlsrv_commit($this->linkDB);
    }

    /**
     * @inheritDoc
     */
    public function transactionRollback()
    {
        sqlsrv_rollback($this->linkDB);
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
     */
    public function dbExport(&$strFilename, $arrTables)
    {
        // @TODO implement
        return false;
    }

    /**
     * @inheritDoc
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
     * @inheritdoc
     */
    public function getConcatExpression(array $parts)
    {
        return "(" . implode(' + ', $parts) . ")";
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
     * @inheritDoc
     */
    public function encloseColumnName($strColumn)
    {
        return '['.$strColumn.']';
    }

    /**
     * @inheritDoc
     */
    public function encloseTableName($strTable)
    {
        return '['.$strTable.']';
    }

    /**
     * @inheritdoc
     */
    public function getLeastExpression(array $parts): string
    {
        return '(SELECT MIN(x) FROM (VALUES (' . implode('),(', $parts) . ')) AS value(x))';
    }
}

