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

namespace Artemeon\Database\Tests;

use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\TableIndex;
use Artemeon\Database\Schema\TableKey;

class ConnectionTableInformationTest extends ConnectionTestCase
{
    const TEST_TABLE_NAME = "agp_temp_tableinfotest";

    public function testTypeConversion()
    {
        $objDB = $this->getConnection();

        if (in_array(self::TEST_TABLE_NAME, $this->getConnection()->getTables())) {
            $strQuery = "DROP TABLE ".self::TEST_TABLE_NAME;
            $this->getConnection()->_pQuery($strQuery, array());
        }

        $colDefinitions = array();
        $colDefinitions["temp_int"] = array(DataType::STR_TYPE_INT, false);
        $colDefinitions["temp_long"] = array(DataType::STR_TYPE_LONG, true);
        $colDefinitions["temp_double"] = array(DataType::STR_TYPE_DOUBLE, true);
        $colDefinitions["temp_char10"] = array(DataType::STR_TYPE_CHAR10, true);
        $colDefinitions["temp_char20"] = array(DataType::STR_TYPE_CHAR20, true);
        $colDefinitions["temp_char100"] = array(DataType::STR_TYPE_CHAR100, true);
        $colDefinitions["temp_char254"] = array(DataType::STR_TYPE_CHAR254, true);
        $colDefinitions["temp_char500"] = array(DataType::STR_TYPE_CHAR500, true);
        $colDefinitions["temp_text"] = array(DataType::STR_TYPE_TEXT, true);
        $colDefinitions["temp_longtext"] = array(DataType::STR_TYPE_LONGTEXT, true);

        $this->assertTrue($objDB->createTable(self::TEST_TABLE_NAME, $colDefinitions, ["temp_int"]));
        $this->assertTrue($objDB->createIndex(self::TEST_TABLE_NAME, "temp_double", ["temp_double"]));
        $this->assertTrue($objDB->createIndex(self::TEST_TABLE_NAME, "temp_char500", ["temp_char500"]));
        $this->assertTrue($objDB->createIndex(self::TEST_TABLE_NAME, "temp_combined", ["temp_double", "temp_char500"]));

        //load the schema info from the db
        $info = $objDB->getTableInformation(self::TEST_TABLE_NAME);

        $arrKeyNames = array_map(function (TableKey $key) {
            return $key->getName();
        }, $info->getPrimaryKeys());

        $this->assertTrue(in_array("temp_int", $arrKeyNames));

        $arrIndexNames = array_map(function (TableIndex $index) {
            return $index->getName();
        }, $info->getIndexes());

        $this->assertTrue(in_array("temp_double", $arrIndexNames));
        $this->assertTrue(in_array("temp_char500", $arrIndexNames));
        $this->assertTrue(in_array("temp_combined", $arrIndexNames));


    }

}
