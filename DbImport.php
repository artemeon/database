<?php
/*"******************************************************************************************************
*   (c) 2007-2017 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/
declare(strict_types=1);

namespace Kajona\Dbdump\System;

use Kajona\System\System\Database;
use Kajona\System\System\Exception;
use Kajona\System\System\Filesystem;
use Kajona\System\System\StringUtil;
use Kajona\System\System\Zip;
use PHPCodeBrowser\File;
use RuntimeException;

/**
 * Imports a database dump created by the DbExport class.
 * The schema itself must be present, tables are truncated pre importing the data
 * @see DbExport
 *
 * @author sidler@mulchprod.de
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


    public function importFile($strFile)
    {
        if (!$this->validateFile($strFile)) {
            throw new \InvalidArgumentException("File ".$strFile." is no valid dump");
        }

        $strTargetDir = "/project/temp/dbimport_".generateSystemid();
        $this->extractArchive($strFile, $strTargetDir);


        $objFilesystem = new Filesystem();
        $arrFiles = $objFilesystem->getFilelist($strTargetDir, [".ser"]);
        if ($this->validateTargetSchema($arrFiles, $strTargetDir)) {
            foreach ($arrFiles as $strOneFile) {
                $this->importSingleTableFile($strTargetDir."/".$strOneFile);
            }
        }

        $objFilesystem->folderDeleteRecursive($strTargetDir);
        return true;
    }


    private function importSingleTableFile(string $strFile)
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
                    echo "{$intCount}...";

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

    private function importRows($arrRows, $strTable)
    {
        if (count($arrRows) == 0) {
            return;
        }

        $arrCols = array_keys($arrRows[0]);
        $this->objDB->multiInsert($strTable, $arrCols, $arrRows);
    }


    /**
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

            if ($this->bitPrintDebug) {
//                echo "Found valid file {$strFile}".PHP_EOL;
                ob_flush();
                flush();
            }

        }
        return true;
    }

    private function extractArchive($strArchive, $strTargetDir)
    {
        $objZip = new Zip();
        $objZip->extractArchive($strArchive, $strTargetDir);
    }


    private function validateFile($strFilename): bool
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

