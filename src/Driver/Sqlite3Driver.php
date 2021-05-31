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
use SQLite3;

/**
 * db-driver for sqlite3 using the php-sqlite3-interface.
 * Based on the sqlite2 driver by phwolfer
 *
 * @since 3.3.0.1
 * @author sidler@mulchprod.de
 * @package module_system
 */
class Sqlite3Driver extends DriverAbstract
{

    /**
     * @var SQLite3
     */
    private $linkDB;
    private $strDbFile;

    /**
     * @inheritdoc
     */
    public function dbconnect(ConnectionParameters $objParams)
    {
        if ($objParams->getDatabase() == "") {
            return false;
        }

        if ($objParams->getDatabase() === ':memory:') {
            $this->strDbFile = ':memory:';
        } else {
            $this->strDbFile = $objParams->getAttribute(ConnectionParameters::SQLITE3_BASE_PATH) . '/' . $objParams->getDatabase().'.db3';
        }

        try {
            $this->linkDB = new SQLite3($this->strDbFile);
            $this->_pQuery('PRAGMA encoding = "UTF-8"', array());
            $this->linkDB->busyTimeout(5000);

            return true;
        } catch (\Throwable $e) {
            throw new ConnectionException("Error connecting to database", 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function dbclose()
    {
        if ($this->linkDB !== null) {
            $this->linkDB->close();
            $this->linkDB = null;
        }
    }


    private function buildAndCopyTempTables($strTargetTableName, $arrSourceTableInfo, $arrTargetTableInfo)
    {
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

        $bitReturn = $this->_pQuery($strQuery, array());

        //copy all values
        $strQuery = "INSERT INTO ".$strTargetTableName."_temp (".implode(",", $arrTargetColumns).") SELECT ".implode(",", $arrSourceColumns)." FROM ".$strTargetTableName;
        $bitReturn = $bitReturn && $this->_pQuery($strQuery, array());

        $strQuery = "DROP TABLE ".$strTargetTableName;
        $bitReturn = $bitReturn && $this->_pQuery($strQuery, array());

        return $bitReturn && $this->renameTable($strTargetTableName."_temp", $strTargetTableName);
    }

    /**
     * @inheritDoc
     */
    public function changeColumn($strTable, $strOldColumnName, $strNewColumnName, $strNewDatatype)
    {

        $tableDef = $this->getTableInformation($strTable);
        $arrTableInfo = array();
        $arrTargetTableInfo = array();
        foreach ($tableDef->getColumns() as $colDef) {
            $arrNewDef = array(
                "columnName" => $colDef->getName(),
                "columnType" => $colDef->getInternalType()
            );

            $arrTableInfo[] = $arrNewDef;

            if ($colDef->getName() == $strOldColumnName) {
                $arrNewDef = array(
                    "columnName" => $strNewColumnName,
                    "columnType" => $this->getDatatype($strNewDatatype)
                );
            }

            $arrTargetTableInfo[] = $arrNewDef;
        }

        return $this->buildAndCopyTempTables($strTable, $arrTableInfo, $arrTargetTableInfo);
    }

    /**
     * @inheritDoc
     */
    public function removeColumn($strTable, $strColumn)
    {
        $arrTargetTableInfo = array();

        $tableDef = $this->getTableInformation($strTable);
        foreach ($tableDef->getColumns() as $colDef) {
            if ($colDef->getName() != $strColumn) {
                $arrTargetTableInfo[] = array(
                    "columnName" => $colDef->getName(),
                    "columnType" => $colDef->getInternalType()
                );
            }
        }

        return $this->buildAndCopyTempTables($strTable, $arrTargetTableInfo, $arrTargetTableInfo);
    }

    /**
     * @inheritDoc
     */
    public function triggerMultiInsert($strTable, $arrColumns, $arrValueSets, ConnectionInterface $objDb, ?array $arrEscapes): bool
    {
        $sqliteVersion = SQLite3::version();
        if (version_compare('3.7.11', $sqliteVersion['versionString'], '<=')) {
            return parent::triggerMultiInsert($strTable, $arrColumns, $arrValueSets, $objDb, $arrEscapes);
        }
        //legacy code
        $safeColumns = array_map(function ($column) { return $this->encloseColumnName($column); }, $arrColumns);
        $params = [];
        $escapeValues = [];
        $insertStatement = 'INSERT INTO ' . $this->encloseTableName($strTable) . '  (' . implode(',', $safeColumns) . ') ';
        foreach ($arrValueSets as $key => $valueSet) {
            $selectStatement = $key === 0 ? ' SELECT ' : ' UNION SELECT ';
            $insertStatement .= $selectStatement . implode(', ', array_map(function ($column) { return ' ? AS ' . $column; }, $safeColumns));
            $params[] = array_values($valueSet);
            if ($arrEscapes !== null) {
                $escapeValues[] = $arrEscapes;
            }
        }

        return $objDb->_pQuery($insertStatement, array_merge(...$params), $escapeValues !== [] ? array_merge(...$escapeValues) : []);
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

        $strQuery = "INSERT OR REPLACE INTO ".$this->encloseTableName($strTable)." (".implode(", ", $arrMappedColumns).") VALUES (".implode(", ", $arrPlaceholder).")";
        return $this->_pQuery($strQuery, $arrValues);
    }

    /**
     * @inheritDoc
     */
    public function _pQuery($strQuery, $arrParams)
    {
        $strQuery = $this->fixQuoting($strQuery);
        $strQuery = $this->processQuery($strQuery);

        $objStmt = $this->getPreparedStatement($strQuery);
        if ($objStmt === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $strQuery, $arrParams);
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
            throw new QueryException('Could not execute statement: ' . $this->getError(), $strQuery, $arrParams);
        }

        $this->intAffectedRows = $this->linkDB->changes();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getPArray($strQuery, $arrParams): \Generator
    {
        $strQuery = $this->fixQuoting($strQuery);
        $strQuery = $this->processQuery($strQuery);

        $objStmt = $this->getPreparedStatement($strQuery);
        if ($objStmt === false) {
            throw new QueryException('Could not prepare statement: ' . $this->getError(), $strQuery, $arrParams);
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

        $objResult = $objStmt->execute();

        if ($objResult === false) {
            throw new QueryException('Could not execute statement', $strQuery, $arrParams);
        }

        while ($arrTemp = $objResult->fetchArray(SQLITE3_ASSOC)) {
            yield $arrTemp;
        }
    }

    /**
     * @inheritDoc
     */
    public function getError()
    {
        return $this->linkDB->lastErrorMsg();
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
     */
    public function getTableInformation(string $tableName): Table
    {
        $table = new Table($tableName);

        //fetch all columns
        $columnInfo = $this->getPArray("PRAGMA table_info('{$tableName}')", []) ?: [];
        foreach ($columnInfo as $arrOneColumn) {
            $col = new TableColumn($arrOneColumn["name"]);
            $col->setInternalType($this->getCoreTypeForDbType($arrOneColumn));
            $col->setDatabaseType($this->getDatatype($col->getInternalType()));
            $col->setNullable($arrOneColumn["notnull"] == 0);
            $table->addColumn($col);

            if ($arrOneColumn['pk'] == 1) {
                $table->addPrimaryKey(new TableKey($arrOneColumn["name"]));
            }
        }

        //fetch all indexes
        $indexes = $this->getPArray("SELECT * FROM sqlite_master WHERE type = 'index' AND tbl_name = ?", [$tableName]) ?: [];
        foreach ($indexes as $indexInfo) {
            $index = new TableIndex($indexInfo['name']);
            $index->setDescription($indexInfo['sql'] ?? '');
            $table->addIndex($index);
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
        $val = strtolower(trim($infoSchemaRow["type"]));
        if ($val == "integer") {
            return DataType::STR_TYPE_INT;
        } elseif ($val == "real") {
            return DataType::STR_TYPE_DOUBLE;
        } elseif ($val == "text") {
            return DataType::STR_TYPE_TEXT;
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function createTable($strName, $arrFields, $arrKeys)
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
    public function hasIndex($strTable, $strName): bool
    {
        $arrIndex = iterator_to_array($this->getPArray("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?", [$strTable, $strName]), false);
        return count($arrIndex) > 0;
    }

    /**
     * @inheritDoc
     */
    public function transactionBegin()
    {
        $this->_pQuery("BEGIN TRANSACTION", array());
    }

    /**
     * @inheritDoc
     */
    public function transactionCommit()
    {
        $this->_pQuery("COMMIT TRANSACTION", array());
    }

    /**
     * @inheritDoc
     */
    public function transactionRollback()
    {
        $this->_pQuery("ROLLBACK TRANSACTION", array());
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
     */
    public function getDatatype($strType)
    {
        $strReturn = "";

        if ($strType == DataType::STR_TYPE_INT) {
            $strReturn .= " INTEGER ";
        } elseif ($strType == DataType::STR_TYPE_LONG) {
            $strReturn .= " INTEGER ";
        } elseif ($strType == DataType::STR_TYPE_DOUBLE) {
            $strReturn .= " REAL ";
        } elseif ($strType == DataType::STR_TYPE_CHAR10) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DataType::STR_TYPE_CHAR20) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DataType::STR_TYPE_CHAR100) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DataType::STR_TYPE_CHAR254) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DataType::STR_TYPE_CHAR500) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DataType::STR_TYPE_TEXT) {
            $strReturn .= " TEXT ";
        } elseif ($strType == DataType::STR_TYPE_LONGTEXT) {
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
        $strQuery = preg_replace_callback('/\?/', static function($value): string {
            static $i = 0;
            $i++;
            return ':param' . $i;
        }, $strQuery);

        return $strQuery;
    }

    /**
     * Prepares a statement or uses an instance from the cache
     *
     * @param string $strQuery
     *
     * @return \SQLite3Stmt|false
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

    /**
     * @inheritDoc
     */
    public function encloseTableName($strTable)
    {
        return "'".$strTable."'";
    }

    /**
     * @inheritdoc
     */
    public function getConcatExpression(array $parts)
    {
        return implode(' || ', $parts);
    }

    /**
     * @inheritdoc
     */
    public function getLeastExpression(array $parts): string
    {
        return 'MIN(' . implode(', ', $parts) . ')';
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
