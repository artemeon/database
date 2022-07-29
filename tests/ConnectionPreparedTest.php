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
        $connection = $this->getConnection();


        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPRow($strQuery, array());
        $this->assertTrue(count($arrRow) >= 9, "testDataBase getRow count");
        $this->assertEquals($arrRow["temp_char10"], "char10-1", "testDataBase getRow content");


        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_char10 = ? ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPRow($strQuery, array('char10-2'));
        $this->assertTrue(count($arrRow) >= 9, "testDataBase getRow count");
        $this->assertEquals($arrRow["temp_char10"], "char10-2", "testDataBase getRow content");

        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPArray($strQuery, array());
        $this->assertEquals(count($arrRow), 50, "testDataBase getArray count");

        $intI = 1;
        foreach ($arrRow as $arrSingleRow)
            $this->assertEquals($arrSingleRow["temp_char10"], 'char10-' . $intI++, "testDataBase getArray content");

        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_char10 = ? ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPArray($strQuery, array('char10-2'));
        $this->assertEquals(count($arrRow), 1, "testDataBase getArray count");

        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPArray($strQuery, array(), 0, 9);
        $this->assertEquals(count($arrRow), 10, "testDataBase getArraySection count");
        $this->assertEquals($arrRow[0]["temp_char10"], 'char10-1');
        $this->assertEquals($arrRow[9]["temp_char10"], 'char10-10');

        $intI = 1;
        foreach ($arrRow as $arrSingleRow) {
            $this->assertEquals($arrSingleRow["temp_char10"], 'char10-' . $intI++, "testDataBase getArraySection content");
        }


        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPArray($strQuery, array(), 5, 14);
        $this->assertEquals(count($arrRow), 10, "testDataBase getArraySection offset count");
        $this->assertEquals($arrRow[0]["temp_char10"], 'char10-6');
        $this->assertEquals($arrRow[9]["temp_char10"], 'char10-15');


        $this->flushDBCache();
        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_char10 LIKE ? ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPArray($strQuery, array("%"), 0, 9);
        $this->assertEquals(count($arrRow), 10, "testDataBase getArraySection param count");

        $intI = 1;
        foreach ($arrRow as $arrSingleRow) {
            $this->assertEquals($arrSingleRow["temp_char10"], 'char10-' . $intI++, "testDataBase getArraySection param content");
        }

        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . "  WHERE temp_char10 = ? AND temp_char20 = ? ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPArray($strQuery, array('char10-2', 'char202'));
        $this->assertEquals(count($arrRow), 0, "testDataBase getArray 2 params count");

        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . "  WHERE temp_char10 = ? AND temp_char20 = ? ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPArray($strQuery, array('2', null));
        $this->assertEquals(count($arrRow), 0, "testDataBase getArray 2 params count");
        
        $strQuery = "DROP TABLE " . self::TEST_TABLE_NAME;
        $this->assertTrue($connection->_pQuery($strQuery, array()), "testDataBase dropTable");
    }

    public function testFloatHandling()
    {
        $connection = $this->getConnection();

        $arrFields = array();
        $arrFields["temp_id"] = array("char20", false);
        $arrFields["temp_long"] = array("long", true);
        $arrFields["temp_double"] = array("double", true);

        $this->assertTrue($connection->createTable("agp_temp_autotest_float", $arrFields, array("temp_id")), "testDataBase createTable");

        $connection->_pQuery("DELETE FROM " . self::TEST_TABLE_NAME . " WHERE 1 = 1", array());

        $connection->multiInsert(
            self::TEST_TABLE_NAME,
            array("temp_id", "temp_bigint", "temp_float"),
            array(array("id1", 123456, 1.7), array("id2", "123456", "1.7"))
        );

        $arrRow = $connection->getPRow("SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", array("id1"));

        $this->assertEquals(123456, $arrRow["temp_bigint"]);
        $this->assertEquals(1.7, round((float) $arrRow["temp_float"], 1));

        $arrRow = $connection->getPRow("SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", array("id2"));

        $this->assertEquals(123456, $arrRow["temp_bigint"]);
        $this->assertEquals(1.7, round((float) $arrRow["temp_float"], 1));
    }
}

