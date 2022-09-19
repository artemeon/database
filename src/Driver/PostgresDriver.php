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
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * db-driver for postgres using the php-postgres-interface
 *
 * @package module_system
 * @author sidler@mulchprod.de
 */
class PostgresDriver extends DriverAbstract
{

    private $linkDB; //DB-Link

    /** @var ConnectionParameters */
    private $objCfg = null;

    private $strDumpBin = "pg_dump"; //Binary to dump db (if not in path, add the path here)
    private $strRestoreBin = "psql"; //Binary to restore db (if not in path, add the path here)

    private $arrCxInfo = array();

    /**
     * @inheritdoc
     */
    public function dbconnect(ConnectionParameters $objParams)
    {
        $port = $objParams->getPort();
        if (empty($port)) {
            $port = 5432;
        }

        $this->objCfg = $objParams;
        $this->linkDB = pg_connect("host='".$objParams->getHost()."' port='".$port."' dbname='".$objParams->getDatabase()."' user='".$objParams->getUsername()."' password='".$objParams->getPassword()."'");

        if (!$this->linkDB) {
            throw new ConnectionException("Error connecting to database: " . pg_last_error());
        }

        $this->_pQuery("SET client_encoding='UTF8'", array());

        $this->arrCxInfo = pg_version($this->linkDB);
        return true;

    }

    /**
     * @inheritDoc
     */
    public function dbclose()
    {
        if ($this->linkDB !== null && is_resource($this->linkDB)) {
            pg_close($this->linkDB);
            $this->linkDB = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function _pQuery($strQuery, $arrParams)
    {
        $strQuery = $this->processQuery($strQuery);
        $strName = $this->getPreparedStatementName($strQuery);
        if ($strName === false) {
            return false;
        }

        $objResult = pg_execute($this->linkDB, $strName, $arrParams);

        if ($objResult !== false) {
            $this->intAffectedRows = pg_affected_rows($objResult);

            return true;
        } else {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getPArray($strQuery, $arrParams)
    {
        $arrReturn = array();
        $intCounter = 0;

        $strQuery = $this->processQuery($strQuery);
        $strName = $this->getPreparedStatementName($strQuery);
        if ($strName === false) {
            throw new QueryException('Could not prepare statement', $strQuery, $arrParams);
        }

        $resultSet = pg_execute($this->linkDB, $strName, $arrParams);

        if ($resultSet === false) {
            throw new QueryException('Could not execute query', $strQuery, $arrParams);
        }

        while ($arrRow = pg_fetch_array($resultSet, null, PGSQL_ASSOC)) {
            //conversions to remain compatible:
            //   count --> COUNT(*)
            if (isset($arrRow["count"])) {
                $arrRow["COUNT(*)"] = $arrRow["count"];
            }

            $arrReturn[$intCounter++] = $arrRow;
        }

        pg_free_result($resultSet);

        return $arrReturn;
    }

    /**
     * Postgres supports UPSERTS since 9.5, see http://michael.otacoo.com/postgresql-2/postgres-9-5-feature-highlight-upsert/.
     * A fallback is the base select / update method.
     *
     * @inheritDoc
     */
    public function insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns)
    {

        //get the current postgres version to validate the upsert features
        if (version_compare($this->arrCxInfo["server"], "9.5", "<")) {
            //base implementation
            return parent::insertOrUpdate($strTable, $arrColumns, $arrValues, $arrPrimaryColumns);
        }

        $arrPlaceholder = array();
        $arrMappedColumns = array();
        $arrKeyValuePairs = array();

        foreach ($arrColumns as $intI => $strOneCol) {
            $arrPlaceholder[] = "?";
            $arrMappedColumns[] = $this->encloseColumnName($strOneCol);

            if (!in_array($strOneCol, $arrPrimaryColumns)) {
                $arrKeyValuePairs[] = $this->encloseColumnName($strOneCol)." = ?";
                $arrValues[] = $arrValues[$intI];
            }
        }

        if (empty($arrKeyValuePairs)) {
            $strQuery = "INSERT INTO ".$this->encloseTableName($strTable)." (".implode(", ", $arrMappedColumns).") VALUES (".implode(", ", $arrPlaceholder).")
                        ON CONFLICT ON CONSTRAINT ".$strTable."_pkey DO NOTHING";
        } else {
            $strQuery = "INSERT INTO ".$this->encloseTableName($strTable)." (".implode(", ", $arrMappedColumns).") VALUES (".implode(", ", $arrPlaceholder).")
                        ON CONFLICT ON CONSTRAINT ".$strTable."_pkey DO UPDATE SET ".implode(", ", $arrKeyValuePairs);
        }

        return $this->_pQuery($strQuery, $arrValues);
    }

    /**
     * @inheritDoc
     */
    public function getError()
    {
        $strError = pg_last_error($this->linkDB);
        return $strError;
    }

    /**
     * @inheritDoc
     */
    public function getTables()
    {
        return $this->getPArray("SELECT *, table_name as name FROM information_schema.tables WHERE table_schema = 'public'", array());
    }

    /**
     * @inheritDoc
     */
    public function getTableInformation(string $tableName): Table
    {
        $table = new Table($tableName);

        // fetch all columns
        $columnInfo = $this->getPArray("SELECT * FROM information_schema.columns WHERE table_name = ?", [$tableName]) ?: [];
        foreach ($columnInfo as $arrOneColumn) {
            $col = new TableColumn($arrOneColumn["column_name"]);
            $col->setInternalType($this->getCoreTypeForDbType($arrOneColumn));
            $col->setDatabaseType($this->getDatatype($col->getInternalType()));
            $col->setNullable($arrOneColumn["is_nullable"] == "YES");
            $table->addColumn($col);
        }

        //fetch all indexes
        $indexes = $this->getPArray("select * from pg_indexes where tablename  = ? AND indexname NOT LIKE '%_pkey'", [$tableName]) ?: [];
        foreach ($indexes as $indexInfo) {
            $index = new TableIndex($indexInfo['indexname']);
            //scrape the columns from the indexdef
            $cols = substr($indexInfo['indexdef'], strpos($indexInfo['indexdef'], "(")+1, strpos($indexInfo['indexdef'], ")")-strpos($indexInfo['indexdef'], "(")-1);
            $index->setDescription($cols);
            $table->addIndex($index);
        }

        //fetch all keys
        $query = "SELECT a.attname as column_name
                    FROM pg_class t,
                         pg_class i,
                         pg_index ix,
                         pg_attribute a
                   WHERE t.oid = ix.indrelid
                     AND i.oid = ix.indexrelid
                     AND a.attrelid = t.oid
                     AND a.attnum = ANY(ix.indkey)
                     AND t.relkind = 'r'
                     AND ix.indisprimary = 't'
                     AND t.relname LIKE ?
                ORDER BY t.relname, i.relname";

        $keys = $this->getPArray($query, [$tableName]) ?: [];
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
        if ($infoSchemaRow["data_type"] == "integer") {
            return DataType::STR_TYPE_INT;
        } elseif ($infoSchemaRow["data_type"] == "bigint") {
            return DataType::STR_TYPE_LONG;
        } elseif ($infoSchemaRow["data_type"] == "numeric") {
            return DataType::STR_TYPE_DOUBLE;
        } elseif ($infoSchemaRow["data_type"] == "character varying") {
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
            }
        } elseif ($infoSchemaRow["data_type"] == "text") {
            return DataType::STR_TYPE_TEXT;
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
            $strReturn .= " NUMERIC ";
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
            $strReturn .= " TEXT ";
        } else {
            $strReturn .= " VARCHAR( 254 ) ";
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

        return $bitReturn && $this->_pQuery("ALTER TABLE ".$this->encloseTableName($strTable)." ALTER COLUMN ".$this->encloseColumnName($strNewColumnName)." TYPE ".$this->getDatatype($strNewDatatype), array());
    }

    /**
     * @inheritDoc
     */
    public function createTable($strName, $arrFields, $arrKeys)
    {
        $strQuery = "";

        //build the mysql code
        $strQuery .= "CREATE TABLE ".$this->encloseTableName($strName)." ( \n";

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
        $strQuery .= " PRIMARY KEY ( ".implode(" , ", $arrKeys)." ) \n";
        $strQuery .= ") ";

        return $this->_pQuery($strQuery, array());
    }

    /**
     * @inheritdoc
     */
    public function hasIndex($strTable, $strName): bool
    {
        $arrIndex = $this->getPArray("SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$strTable, $strName]);
        return count($arrIndex) > 0;
    }

    /**
     * @inheritDoc
     */
    public function transactionBegin()
    {
        $strQuery = "BEGIN";
        $this->_pQuery($strQuery, array());
    }

    /**
     * @inheritDoc
     */
    public function transactionCommit()
    {
        $str_pQuery = "COMMIT";
        $this->_pQuery($str_pQuery, array());
    }

    /**
     * @inheritDoc
     */
    public function transactionRollback()
    {
        $strQuery = "ROLLBACK";
        $this->_pQuery($strQuery, array());
    }

    /**
     * @inheritDoc
     */
    public function getDbInfo()
    {
        return pg_version($this->linkDB);
    }



    //--- DUMP & RESTORE ------------------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function dbExport(&$strFilename, $arrTables)
    {
        $strTables = "-t ".implode(" -t ", $arrTables);

        $strCommand = '';
        if ($this->objCfg->getPassword() != "") {
            if ($this->isWinOs()) {
                $strCommand .= "SET \"PGPASSWORD=".$this->objCfg->getPassword()."\" && ";
            } else {
                $strCommand .= "PGPASSWORD=\"".$this->objCfg->getPassword()."\" ";
            }
        }

        $port = $this->objCfg->getPort();
        if (empty($port)) {
            $port = 5432;
        }

        if ($this->handlesDumpCompression()) {
            $strFilename .= ".gz";
            $strCommand .= $this->strDumpBin." --clean --no-owner -h".$this->objCfg->getHost().($this->objCfg->getUsername() != "" ? " -U".$this->objCfg->getUsername() : "")." -p".$port." ".$strTables." ".$this->objCfg->getDatabase()." | gzip > \"".$strFilename."\"";
        } else {
            $strCommand .= $this->strDumpBin." --clean --no-owner -h".$this->objCfg->getHost().($this->objCfg->getUsername() != "" ? " -U".$this->objCfg->getUsername() : "")." -p".$port." ".$strTables." ".$this->objCfg->getDatabase()." > \"".$strFilename."\"";
        }

        $process = Process::fromShellCommandline($strCommand);
        $process->setTimeout(3600.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->isSuccessful();
    }

    /**
     * @inheritDoc
     */
    public function dbImport($strFilename)
    {
        $strCommand= '';
        if ($this->objCfg->getPassword() != "") {
            if ($this->isWinOs()) {
                $strCommand .= "SET \"PGPASSWORD=".$this->objCfg->getPassword()."\" && ";
            } else {
                $strCommand .= "PGPASSWORD=\"".$this->objCfg->getPassword()."\" ";
            }
        }

        $port = $this->objCfg->getPort();
        if (empty($port)) {
            $port = 5432;
        }


        if ($this->handlesDumpCompression() && pathinfo($strFilename, PATHINFO_EXTENSION) === 'gz') {
            $strCommand .= " gunzip -c \"".$strFilename."\" | ".$this->strRestoreBin." -q -h".$this->objCfg->getHost().($this->objCfg->getUsername() != "" ? " -U".$this->objCfg->getUsername() : "")." -p".$port." ".$this->objCfg->getDatabase()."";
        } else {
            $strCommand .= $this->strRestoreBin." -q -h".$this->objCfg->getHost().($this->objCfg->getUsername() != "" ? " -U".$this->objCfg->getUsername() : "")." -p".$port." ".$this->objCfg->getDatabase()." < \"".$strFilename."\"";
        }

        $process = Process::fromShellCommandline($strCommand);
        $process->setTimeout(3600.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->isSuccessful();
    }

    public function encloseTableName($strTable)
    {
        return "\"{$strTable}\"";
    }

    /**
     * @inheritDoc
     */
    public function escape($strValue)
    {
        return str_replace("\\", "\\\\", $strValue);
    }

    /**
     * Transforms the query into a valid postgres-syntax
     *
     * @param string $strQuery
     *
     * @return string
     */
    protected function processQuery($strQuery)
    {
        $strQuery = preg_replace_callback('/\?/', function($strValue){
            static $intI = 0;
            $intI++;
            return '$' . $intI;
        }, $strQuery);

        $strQuery = str_replace(" LIKE ", " ILIKE ", $strQuery);

        return $strQuery;
    }

    /**
     * Does as cache-lookup for prepared statements.
     * Reduces the number of pre-compiles at the db-side.
     *
     * @param string $strQuery
     *
     * @return string|bool
     * @since 3.4
     */
    private function getPreparedStatementName($strQuery)
    {
        $strSum = md5($strQuery);
        if (in_array($strSum, $this->arrStatementsCache)) {
            return $strSum;
        }

        if (pg_prepare($this->linkDB, $strSum, $strQuery)) {
            $this->arrStatementsCache[] = $strSum;
        } else {
            return false;
        }

        return $strSum;
    }

    /**
     * @inheritdoc
     */
    public function appendLimitExpression($strQuery, $intStart, $intEnd)
    {
        //calculate the end-value:
        $intEnd = $intEnd - $intStart + 1;
        //add the limits to the query
        return $strQuery." LIMIT  ".$intEnd." OFFSET ".$intStart;
    }

    /**
     * @inheritDoc
     */
    public function flushQueryCache()
    {
        $this->_pQuery("DISCARD ALL", array());

        parent::flushQueryCache();
    }
}
