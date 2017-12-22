<?php
/*"******************************************************************************************************
*   (c) 2007-2017 by Kajona, www.kajona.de                                                              *
*       Published under the GNU LGPL v2.1, see /system/licence_lgpl.txt                                 *
********************************************************************************************************/
declare(strict_types=1);

namespace Kajona\Dbdump\System;

use Kajona\System\System\Database;
use Kajona\System\System\Filesystem;
use Kajona\System\System\Zip;

/**
 * Creates a file-based backup of the current database tables.
 * A file is created per table, the data itself is streamed.
 *
 * @author sidler@mulchprod.de
 */
class DbExport
{

    const LINE_SEPARATOR = "§%§%§\n";
    /**
     * @var Database
     */
    private $objDB;

    /**
     * DbExport constructor.
     * @param Database $objDB
     */
    public function __construct(Database $objDB)
    {
        $this->objDB = $objDB;
    }


    public function createExport()
    {
        $strTarget = "/project/temp/dbexport_".generateSystemid();

        $objFilesystem = new Filesystem();
        $objFilesystem->folderCreate($strTarget);

        foreach ($this->objDB->getTables() as $strTable) {
            $this->exportTable($strTable, $strTarget);
        }

        $this->zipTargetDir($strTarget, "/project/dbdumps/dbdump_kj_".time().".zip");

        $objFilesystem->folderDeleteRecursive($strTarget);
    }


    private function zipTargetDir($strSourceDir, $strTargetFilename): bool
    {
        $objZip = new Zip();
        $objZip->openArchiveForWriting($strTargetFilename);

        if (is_dir(_realpath_.$strSourceDir)) {
            $objFilesystem = new Filesystem();
            $arrFiles = $objFilesystem->getCompleteList($strSourceDir, array(), array(), array(".", ".."));
            foreach ($arrFiles["files"] as $arrOneFile) {
                if (!$objZip->addFile($arrOneFile["filepath"], basename($arrOneFile["filepath"]))) {
                    return false;
                }
            }


        }

        $objZip->closeArchive();
        return true;
    }


    private function exportTable($strTable, $strTargetDir): bool
    {
        //fetch the columns in order to get a sort-col
        $arrColumns = $this->objDB->getColumnsOfTable($strTable);

        $strTargetFile = $strTargetDir."/".$strTable;

        $objFile = new Filesystem();
        if (!$objFile->openFilePointer($strTargetFile)) {
            return false;
        }

        foreach ($this->objDB->getGenerator("SELECT * FROM ".$strTable." ORDER BY ".$arrColumns[0]["columnName"]. " ASC") as $arrRow) {
            $objFile->writeToFile(serialize($arrRow).self::LINE_SEPARATOR);
        }

        $objFile->closeFilePointer();

        return true;
    }

}

