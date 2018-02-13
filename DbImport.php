<?php
/*"******************************************************************************************************
*   (c) 2018 ARTEMEON                                                                                   *
*       Published under the GNU LGPL v2.1                                                               *
********************************************************************************************************/
declare(strict_types=1);

namespace Kajona\Dbdump\System;

use Kajona\System\System\Database;
use Kajona\System\System\Exception;
use Kajona\System\System\Filesystem;
use Kajona\System\System\StringUtil;
use Kajona\System\System\Zip;

/**
 * Imports a database dump created by the DbExport class.
 * The schema itself must be present, tables are truncated pre importing the data
 * @see DbExport
 *
 * @author sidler@mulchprod.de
 * @since 7.0
 */
class DbImport
{

    /**
     * @var Database
     */
    private $objDB;

    private $bitPrintDebug;

    /**
     * DbImport constructor.
     * @param Database $objDB
     * @param bool $bitPrintDebug
     */
    public function __construct(Database $objDB, $bitPrintDebug = false)
    {
        $this->objDB = $objDB;
        $this->bitPrintDebug = $bitPrintDebug;
    }


    /**
     * Imports a dump file into the current database.
     * The file must have been created by the matching exporter and needs to match the same schema version.
     * @param $strFile
     * @return bool
     * @throws Exception
     */
    public function importFile(string $strFile): bool
    {
        if (!$this->validateFile($strFile)) {
            throw new \InvalidArgumentException("File ".$strFile." is no valid dump");
        }

        $strTargetDir = "/project/temp/dbimport_".generateSystemid();
        $objZip = new Zip();
        if (!$objZip->extractArchive($strFile, $strTargetDir)) {
            return false;
        }

        $bitReturn = true;
        $objFilesystem = new Filesystem();
        $arrFiles = $objFilesystem->getFilelist($strTargetDir, [".ser"]);
        if ($this->validateTargetSchema($arrFiles, $strTargetDir)) {
            foreach ($arrFiles as $strOneFile) {
                if (!$this->importSingleTableFile($strTargetDir."/".$strOneFile)) {
                    $bitReturn = false;
                    break;
                }
            }
        }

        $objFilesystem->folderDeleteRecursive($strTargetDir);
        return $bitReturn;
    }

    /**
     * Imports a single file of the dump, so a single table
     * @param string $strFile
     * @return bool
     */
    private function importSingleTableFile(string $strFile): bool
    {
        $strTableName = StringUtil::substring(basename($strFile), 0, -4);
        $strTableName = StringUtil::replace(_dbprefix_, "", $strTableName);

        $objFileystem = new Filesystem();
        $objFileystem->openFilePointer($strFile, 'r');


        if ($this->bitPrintDebug) {
            echo "Importing into table ".str_pad($strTableName, 25)."  rows: ";
            ob_flush();
            flush();
        }


        $this->objDB->_pQuery("DELETE FROM "._dbprefix_.$strTableName." WHERE 1=1", []);


        $arrRows = [];
        $intCount = 0;
        $intPrint = 0;
        while ($strRawData = $objFileystem->readLineByCustomDelimiterFromFile(DbExport::LINE_SEPARATOR)) {
            $arrRows[] = unserialize($strRawData);
            $intCount++;

            if (count($arrRows) > 100) {
                $this->importRows($arrRows, $strTableName);
                $arrRows = [];

                if ($this->bitPrintDebug) {
                    echo str_pad($intCount."", 10, " ");

                    if ($intPrint++ > 15) {
                        echo PHP_EOL.str_pad("", 54);
                        $intPrint = 0;
                    }
                    ob_flush();
                    flush();
                }

            }
        }

        $this->importRows($arrRows, $strTableName);

        if ($this->bitPrintDebug) {
            echo "{$intCount}".PHP_EOL;
            ob_flush();
            flush();
        }

        return true;
    }

    /**
     * Imports a set of rows into a target table
     * @param array $arrRows
     * @param string $strTable
     * @return bool
     */
    private function importRows(array $arrRows, string $strTable): bool
    {
        if (count($arrRows) == 0) {
            return true;
        }

        $arrCols = array_keys($arrRows[0]);
        Database::$bitDbSafeStringEnabled = false;
        $bitReturn = $this->objDB->multiInsert($strTable, $arrCols, $arrRows);
        Database::$bitDbSafeStringEnabled = true;
        return $bitReturn;
    }


    /**
     * Compares the dump files (columns) and the currently available schema
     * @param $arrFiles
     * @param $strBaseDir
     * @return bool
     * @throws Exception
     */
    private function validateTargetSchema(array $arrFiles, string $strBaseDir): bool
    {
        $arrTables = $this->objDB->getTables();

        foreach ($arrFiles as $strFile) {
            $strImportTableName = StringUtil::substring($strFile, 0, -4);

            $bitFound = true;
            foreach ($arrTables as $strTable) {
                if ($strTable == $strImportTableName) {
                    $bitFound = true;
                }
            }

            if (!$bitFound) {
                if ($this->bitPrintDebug) {
                    echo "Table for file {$strFile} not found in current schema".PHP_EOL;
                    ob_flush();
                    flush();
                }

                throw new Exception("Table for file {$strFile} not found in current schema", Exception::$level_ERROR);
            }


            //validate the columns of the table
            $objFilesystem = new Filesystem();
            $objFilesystem->openFilePointer($strBaseDir."/".$strFile, 'r');
            $strLine = $objFilesystem->readLineByCustomDelimiterFromFile(DbExport::LINE_SEPARATOR);

            if ($strLine !== false) {
                $arrRow = unserialize($strLine);

                $arrCols = $this->objDB->getColumnsOfTable($strImportTableName);
                if (count($arrRow) != count($arrCols)) {
                    if ($this->bitPrintDebug) {
                        echo "Table of columns for {$strFile} not matching in current schema".PHP_EOL;
                        ob_flush();
                        flush();
                    }

                    throw new Exception("Nr of columns for {$strFile} not matching current schema", Exception::$level_ERROR);
                }

                foreach ($arrCols as $arrSingleCol) {
                    if (!array_key_exists($arrSingleCol["columnName"], $arrRow)) {
                        if ($this->bitPrintDebug) {
                            echo "Mismatching column {$arrSingleCol["columnName"]} in {$strFile}".PHP_EOL;
                            ob_flush();
                            flush();
                        }

                        throw new Exception("Mismatching column {$arrSingleCol["columnName"]} in {$strFile}", Exception::$level_ERROR);
                    }
                }

            }

        }
        return true;
    }


    /**
     * Validates the current file, e.g. if a marker-file is present
     * @param string $strFilename
     * @return bool
     */
    public function validateFile(string $strFilename): bool
    {
        $objZip = new Zip();
        if ($objZip->isZipFile($strFilename) && $objZip->getFileFromArchive($strFilename, DbExport::MARKER_FILE) !== false) {
            $arrFile = json_decode($objZip->getFileFromArchive($strFilename, DbExport::MARKER_FILE));
            if ($arrFile != null) {
                return true;
            }
        }

        return false;
    }
}
