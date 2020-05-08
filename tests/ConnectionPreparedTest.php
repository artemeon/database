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

class ConnectionPreparedTest extends ConnectionTestCase
{
    public function test()
    {

        $objDB = $this->getConnection();

        //echo "current driver: " . Carrier::getInstance()->getObjConfig()->getConfig("dbdriver") . "\n";

        
        $arrFields = array();
        $arrFields["temp_id"] = array("char20", false);
        $arrFields["temp_long"] = array("long", true);
        $arrFields["temp_double"] = array("double", true);
        $arrFields["temp_char10"] = array("char10", true);
        $arrFields["temp_char20"] = array("char20", true);
        $arrFields["temp_char100"] = array("char100", true);
        $arrFields["temp_char254"] = array("char254", true);
        $arrFields["temp_char500"] = array("char500", true);
        $arrFields["temp_text"] = array("text", true);

        $this->assertTrue($objDB->createTable("agp_temp_autotest", $arrFields, array("temp_id")), "testDataBase createTable");


        for ($intI = 1; $intI <= 50; $intI++) {
            $strQuery = "INSERT INTO agp_temp_autotest
                (temp_id, temp_long, temp_double, temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $this->assertTrue($objDB->_pQuery($strQuery, array($this->generateSystemid(), ("123456" . $intI), ("23.45" . $intI), $intI, "char20" . $intI, "char100" . $intI, "char254" . $intI, "char500" . $intI, "text" . $intI)), "testDataBase insert");
        }


        $strQuery = "SELECT * FROM agp_temp_autotest ORDER BY temp_long ASC";
        $arrRow = $objDB->getPRow($strQuery, array());
        $this->assertTrue(count($arrRow) >= 9, "testDataBase getRow count");
        $this->assertEquals($arrRow["temp_char10"], "1", "testDataBase getRow content");


        $strQuery = "SELECT * FROM agp_temp_autotest WHERE temp_char10 = ? ORDER BY temp_long ASC";
        $arrRow = $objDB->getPRow($strQuery, array('2'));
        $this->assertTrue(count($arrRow) >= 9, "testDataBase getRow count");
        $this->assertEquals($arrRow["temp_char10"], "2", "testDataBase getRow content");

        $strQuery = "SELECT * FROM agp_temp_autotest ORDER BY temp_long ASC";
        $arrRow = $objDB->getPArray($strQuery, array());
        $this->assertEquals(count($arrRow), 50, "testDataBase getArray count");

        $intI = 1;
        foreach ($arrRow as $arrSingleRow)
            $this->assertEquals($arrSingleRow["temp_char10"], $intI++, "testDataBase getArray content");

        $strQuery = "SELECT * FROM agp_temp_autotest  WHERE temp_char10 = ? ORDER BY temp_long ASC";
        $arrRow = $objDB->getPArray($strQuery, array('2'));
        $this->assertEquals(count($arrRow), 1, "testDataBase getArray count");

        $strQuery = "SELECT * FROM agp_temp_autotest ORDER BY temp_long ASC";
        $arrRow = $objDB->getPArray($strQuery, array(), 0, 9);
        $this->assertEquals(count($arrRow), 10, "testDataBase getArraySection count");
        $this->assertEquals($arrRow[0]["temp_char10"], 1);
        $this->assertEquals($arrRow[9]["temp_char10"], 10);

        $intI = 1;
        foreach ($arrRow as $arrSingleRow) {
            $this->assertEquals($arrSingleRow["temp_char10"], $intI++, "testDataBase getArraySection content");
        }


        $strQuery = "SELECT * FROM agp_temp_autotest ORDER BY temp_long ASC";
        $arrRow = $objDB->getPArray($strQuery, array(), 5, 14);
        $this->assertEquals(count($arrRow), 10, "testDataBase getArraySection offset count");
        $this->assertEquals($arrRow[0]["temp_char10"], 6);
        $this->assertEquals($arrRow[9]["temp_char10"], 15);


        $this->flushDBCache();
        $strQuery = "SELECT * FROM agp_temp_autotest WHERE temp_char10 LIKE ? ORDER BY temp_long ASC";
        $arrRow = $objDB->getPArray($strQuery, array("%"), 0, 9);
        $this->assertEquals(count($arrRow), 10, "testDataBase getArraySection param count");

        $intI = 1;
        foreach ($arrRow as $arrSingleRow) {
            $this->assertEquals($arrSingleRow["temp_char10"], $intI++, "testDataBase getArraySection param content");
        }

        $strQuery = "SELECT * FROM agp_temp_autotest  WHERE temp_char10 = ? AND temp_char20 = ? ORDER BY temp_long ASC";
        $arrRow = $objDB->getPArray($strQuery, array('2', 'char202'));
        $this->assertEquals(count($arrRow), 1, "testDataBase getArray 2 params count");

        $strQuery = "SELECT * FROM agp_temp_autotest  WHERE temp_char10 = ? AND temp_char20 = ? ORDER BY temp_long ASC";
        $arrRow = $objDB->getPArray($strQuery, array('2', null));
        $this->assertEquals(count($arrRow), 0, "testDataBase getArray 2 params count");
        
        $strQuery = "DROP TABLE agp_temp_autotest";
        $this->assertTrue($objDB->_pQuery($strQuery, array()), "testDataBase dropTable");

    }


    public function testFloatHandling()
    {

        $objDB = $this->getConnection();

        
        $arrFields = array();
        $arrFields["temp_id"] = array("char20", false);
        $arrFields["temp_long"] = array("long", true);
        $arrFields["temp_double"] = array("double", true);

        $this->assertTrue($objDB->createTable("agp_temp_autotest_float", $arrFields, array("temp_id")), "testDataBase createTable");

        $objDB->_pQuery("DELETE FROM agp_temp_autotest_float WHERE 1 = 1", array());

        $objDB->multiInsert(
            "agp_temp_autotest_float",
            array("temp_id", "temp_long", "temp_double"),
            array(array("id1", 123456, 1.7), array("id2", "123456", "1.7"))
        );

        $arrRow = $objDB->getPRow("SELECT * FROM agp_temp_autotest_float WHERE temp_id = ?", array("id1"));

        $this->assertEquals(123456, $arrRow["temp_long"]);
        $this->assertEquals(1.7, round($arrRow["temp_double"], 1));

        $arrRow = $objDB->getPRow("SELECT * FROM agp_temp_autotest_float WHERE temp_id = ?", array("id2"));

        $this->assertEquals(123456, $arrRow["temp_long"]);
        $this->assertEquals(1.7, round($arrRow["temp_double"], 1));

        $strQuery = "DROP TABLE agp_temp_autotest_float";
        $this->assertTrue($objDB->_pQuery($strQuery, array()), "testDataBase dropTable");

    }
}

