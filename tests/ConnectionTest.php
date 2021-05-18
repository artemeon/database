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

use Artemeon\Database\Driver\Oci8Driver;
use Artemeon\Database\Driver\PostgresDriver;
use Artemeon\Database\Driver\SqlsrvDriver;
use Artemeon\Database\Schema\DataType;

class ConnectionTest extends ConnectionTestCase
{
    public function testRenameTable()
    {
        $connection = $this->getConnection();
        $newName = self::TEST_TABLE_NAME . '_new';

        if ($connection->hasTable($newName)) {
            $connection->dropTable($newName);
        }

        $this->assertTrue($connection->hasTable(self::TEST_TABLE_NAME));
        $this->assertFalse($connection->hasTable($newName));

        $this->assertTrue($connection->renameTable(self::TEST_TABLE_NAME, $newName));
        $this->flushDBCache();

        $this->assertFalse($connection->hasTable(self::TEST_TABLE_NAME));
        $this->assertTrue($connection->hasTable($newName));
    }

    public function testCreateIndex()
    {
        $connection = $this->getConnection();

        $bitResult = $connection->createIndex(self::TEST_TABLE_NAME, "foo_index", ["temp_char10", "temp_char20"]);

        $this->assertTrue($bitResult);
    }

    public function testCreateUnqiueIndex()
    {
        $connection = $this->getConnection();

        $bitResult = $connection->createIndex(self::TEST_TABLE_NAME, "foo_index", ["temp_char10", "temp_char20"], true);

        $this->assertTrue($bitResult);
    }

    public function testHasIndex()
    {
        $connection = $this->getConnection();

        $this->assertFalse($connection->hasIndex(self::TEST_TABLE_NAME, "foo_index"));

        $bitResult = $connection->createIndex(self::TEST_TABLE_NAME, "foo_index", ["temp_char10", "temp_char20"]);

        $this->assertTrue($connection->hasIndex(self::TEST_TABLE_NAME, "foo_index"));
        $this->assertTrue($bitResult);
    }

    public function testDropIndex()
    {
        $connection = $this->getConnection();

        $this->assertFalse($connection->hasIndex(self::TEST_TABLE_NAME, "foo_index2"));
        $this->assertTrue($connection->createIndex(self::TEST_TABLE_NAME, "foo_index2", ["temp_char10", "temp_char20"]));
        $this->assertTrue($connection->hasIndex(self::TEST_TABLE_NAME, "foo_index2"));

        $this->assertTrue($connection->deleteIndex(self::TEST_TABLE_NAME, "foo_index2"));
        $this->assertFalse($connection->hasIndex(self::TEST_TABLE_NAME, "foo_index2"));
    }

    public function testFloatHandling()
    {
        $connection = $this->getConnection();

        $connection->insert(self::TEST_TABLE_NAME, ['temp_id' => 'id1', 'temp_float' => 16.8]);
        $connection->insert(self::TEST_TABLE_NAME, ['temp_id' => 'id2', 'temp_float' => 1000.8]);

        $arrRow = $connection->getPRow("SELECT * FROM " . self::TEST_TABLE_NAME . " where temp_id = ?", array("id1"));
        // MSSQL returns 16.799999237061 instead of 16.8
        $this->assertEquals(16.8, round($arrRow["temp_float"], 1));
        $this->assertEquals("16.8", round($arrRow["temp_float"], 1));

        $arrRow = $connection->getPRow("SELECT * FROM " . self::TEST_TABLE_NAME . " where temp_id = ?", array("id2"));
        $this->assertEquals(1000.8, round($arrRow["temp_float"], 1));
        $this->assertEquals("1000.8", round($arrRow["temp_float"], 1));
    }

    public function testChangeColumn()
    {
        $connection = $this->getConnection();

        $connection->insert(self::TEST_TABLE_NAME, array('temp_id' => 'aaa', 'temp_int' => 111));
        $connection->insert(self::TEST_TABLE_NAME, array('temp_id' => 'bbb', 'temp_int' => 222));

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_id'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_int'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_bigint'));

        $this->assertTrue($connection->changeColumn(self::TEST_TABLE_NAME, "temp_int", "temp_bigint_new", DataType::STR_TYPE_BIGINT));

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_id'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_int'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_bigint_new'));

        $row = $connection->getPRow("SELECT temp_id, temp_bigint_new FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", ['aaa']);
        $this->assertEquals($row["temp_id"], "aaa");
        $this->assertEquals($row["temp_bigint_new"], 111);

        $row = $connection->getPRow("SELECT temp_id, temp_bigint_new FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", ['bbb']);
        $this->assertEquals($row["temp_id"], "bbb");
        $this->assertEquals($row["temp_bigint_new"], 222);
    }

    public function testChangeColumnType()
    {
        $connection = $this->getConnection();

        // test changing a column type with the same column name
        $this->assertTrue($connection->changeColumn(self::TEST_TABLE_NAME, "temp_char500", "temp_char500", DataType::STR_TYPE_CHAR10));
    }

    public function testAddColumn()
    {
        $connection = $this->getConnection();

        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col1'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col2'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col3'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col4'));

        $this->assertTrue($connection->addColumn(self::TEST_TABLE_NAME, 'temp_new_col1', DataType::STR_TYPE_INT));
        $this->assertTrue($connection->addColumn(self::TEST_TABLE_NAME, 'temp_new_col2', DataType::STR_TYPE_INT, true, "NULL"));
        $this->assertTrue($connection->addColumn(self::TEST_TABLE_NAME, 'temp_new_col3', DataType::STR_TYPE_INT, false, "0"));
        $this->assertTrue($connection->addColumn(self::TEST_TABLE_NAME, 'temp_new_col4', DataType::STR_TYPE_INT, true));

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col1'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col2'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col3'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col4'));
    }

    public function testHasColumn()
    {
        $connection = $this->getConnection();

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, "temp_id"));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, "temp_foo"));
    }

    public function testRemoveColumn()
    {
        $connection = $this->getConnection();

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, "temp_bigint"));
        $this->assertTrue($connection->removeColumn(self::TEST_TABLE_NAME, "temp_bigint"));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, "temp_bigint"));
    }

    public function testCreateTable()
    {
        $connection = $this->getConnection();

        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPRow($strQuery, array());
        $this->assertTrue(count($arrRow) >= 9, "testDataBase getRow count");

        $this->assertEquals("20200508095301", $arrRow["temp_bigint"], "testDataBase getRow content");
        $this->assertEquals("23.45", round($arrRow["temp_float"], 2), "testDataBase getRow content");
        $this->assertEquals("char10-1", $arrRow["temp_char10"], "testDataBase getRow content");
        $this->assertEquals("char20-1", $arrRow["temp_char20"], "testDataBase getRow content");
        $this->assertEquals("char100-1", $arrRow["temp_char100"], "testDataBase getRow content");
        $this->assertEquals("char254-1", $arrRow["temp_char254"], "testDataBase getRow content");
        $this->assertEquals("char500-1", $arrRow["temp_char500"], "testDataBase getRow content");
        $this->assertEquals("text-1", $arrRow["temp_text"], "testDataBase getRow content");

        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPArray($strQuery, array());
        $this->assertEquals(count($arrRow), 50, "testDataBase getArray count");

        $intI = 1;
        foreach ($arrRow as $arrSingleRow) {
            $this->assertEquals($arrSingleRow["temp_char10"], 'char10-' . $intI, "testDataBase getArray content");
            $intI++;
        }

        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC";
        $arrRow = $connection->getPArray($strQuery, array(), 0, 9);
        $this->assertEquals(count($arrRow), 10, "testDataBase getArraySection count");

        $intI = 1;
        foreach ($arrRow as $arrSingleRow) {
            $this->assertEquals($arrSingleRow["temp_char10"], 'char10-' . $intI, "testDataBase getArraySection content");
            $intI++;
        }
    }

    public function testCreateTableIndex()
    {
        $connection = $this->getConnection();

        $arrFields = array();
        $arrFields["temp_id"] = array("char20", false);
        $arrFields["temp_bigint"] = array("long", true);
        $arrFields["temp_float"] = array("double", true);
        $arrFields["temp_char10"] = array("char10", true);
        $arrFields["temp_char20"] = array("char20", true);
        $arrFields["temp_char100"] = array("char100", true);
        $arrFields["temp_char254"] = array("char254", true);
        $arrFields["temp_char500"] = array("char500", true);
        $arrFields["temp_text"] = array("text", true);

        $this->assertTrue($connection->createTable("agp_temp_autotest", $arrFields, array("temp_id"), array(array("temp_id", "temp_char10", "temp_char100"), "temp_char254")), "testDataBase createTable");
    }

    public function testEscapeText()
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        $connection->insert(self::TEST_TABLE_NAME, [
            'temp_id' => $systemId,
            'temp_int' => 123456,
            'temp_bigint' => 20200508095300,
            'temp_float' => 23.45,
            'temp_char10' => 'Foo\\Bar',
            'temp_char20' => 'Foo\\Bar\\Baz',
            'temp_char100' => 'Foo\\Bar\\Baz',
            'temp_char254' => 'Foo\\Bar\\Baz',
            'temp_char500' => 'Foo\\Bar\\Baz',
            'temp_text' => 'Foo\\Bar\\Baz',
            'temp_longtext' => 'Foo\\Bar\\Baz',
        ]);

        // like must be escaped
        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_char20 LIKE ?";
        $arrRow = $connection->getPRow($strQuery, array($connection->escape("Foo\\Bar%")));

        $this->assertNotEmpty($arrRow);
        $this->assertEquals('Foo\\Bar\\Baz', $arrRow['temp_char20']);

        // equals needs no escape
        $strQuery = "SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_char20 = ?";
        $arrRow = $connection->getPRow($strQuery, array("Foo\\Bar\\Baz"));

        $this->assertNotEmpty($arrRow);
        $this->assertEquals('Foo\\Bar\\Baz', $arrRow['temp_char20']);
    }

    public function testGetPArray()
    {
        $connection = $this->getConnection();

        $result = $connection->getPArray("SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC", array(), 0, 0);
        $this->assertEquals(1, count($result));
        $this->assertEquals(20200508095301, $result[0]["temp_bigint"]);
        $result = $connection->getPArray("SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC", array(), 0, 7);
        $this->assertEquals(8, count($result));
        for ($intI = 0; $intI < 8; $intI++) {
            $this->assertEquals(20200508095301 + $intI, $result[$intI]["temp_bigint"]);
        }

        $result = $connection->getPArray("SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC", array(), 4, 7);
        $this->assertEquals(4, count($result));
        for ($intI = 4; $intI < 8; $intI++) {
            $this->assertEquals(20200508095301 + $intI, $result[$intI - 4]["temp_bigint"]);
        }
    }

    public function testGetAffectedRows()
    {
        $connection = $this->getConnection();
        $strSystemId = $this->generateSystemid();

        // insert which affects onw row
        $connection->multiInsert(self::TEST_TABLE_NAME,
            array("temp_id", "temp_char20"),
            array(array($this->generateSystemid(), $strSystemId))
        );
        $this->assertEquals(1, $connection->getIntAffectedRows());

        // insert which affects two rows
        $connection->multiInsert(self::TEST_TABLE_NAME,
            array("temp_id", "temp_char20"),
            array(
                array($this->generateSystemid(), $strSystemId),
                array($this->generateSystemid(), $strSystemId)
            )
        );
        $this->assertEquals(2, $connection->getIntAffectedRows());

        $strNewSystemId = $this->generateSystemid();

        // update which affects multiple rows
        $connection->_pQuery("UPDATE " . self::TEST_TABLE_NAME . " SET temp_char20 = ? WHERE temp_char20 = ?", array($strNewSystemId, $strSystemId));
        $this->assertEquals(3, $connection->getIntAffectedRows());

        // update which does not affect a row
        $connection->_pQuery("UPDATE " . self::TEST_TABLE_NAME . " SET temp_char20 = ? WHERE temp_char20 = ?", array($this->generateSystemid(), $this->generateSystemid()));
        $this->assertEquals(0, $connection->getIntAffectedRows());

        // delete which affects two rows
        $connection->_pQuery("DELETE FROM " . self::TEST_TABLE_NAME . " WHERE temp_char20 = ?", array($strNewSystemId));
        $this->assertEquals(3, $connection->getIntAffectedRows());

        // delete which affects no rows
        $connection->_pQuery("DELETE FROM " . self::TEST_TABLE_NAME . " WHERE temp_char20 = ?", array($this->generateSystemid()));
        $this->assertEquals(0, $connection->getIntAffectedRows());
    }

    /**
     * @dataProvider dataPostgresProcessQueryProvider
     * @covers \Artemeon\Database\Driver\PostgresDriver::processQuery
     */
    public function testPostgresProcessQuery($strExpect, $strQuery)
    {
        $objDbPostgres = new PostgresDriver();
        $objReflection = new \ReflectionClass(PostgresDriver::class);

        $objMethod = $objReflection->getMethod("processQuery");

        $objMethod->setAccessible(true);
        $strActual = $objMethod->invoke($objDbPostgres, $strQuery);

        $this->assertEquals($strExpect, $strActual);
    }

    public function dataPostgresProcessQueryProvider()
    {
        return [
            ["UPDATE temp_autotest_temp SET temp_char20 = $1 WHERE temp_char20 = $2", "UPDATE temp_autotest_temp SET temp_char20 = ? WHERE temp_char20 = ?"],
            ["INSERT INTO temp_autotest (temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text) VALUES ($1, $2, $3, $4, $5, $6),\n($7, $8, $9, $10, $11, $12)", "INSERT INTO temp_autotest (temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text) VALUES (?, ?, ?, ?, ?, ?),\n(?, ?, ?, ?, ?, ?)"],
            ["SELECT * FROM temp_autotest WHERE temp_char10 = $1 AND temp_char20 = $2 AND temp_char100 = $3", "SELECT * FROM temp_autotest WHERE temp_char10 = ? AND temp_char20 = ? AND temp_char100 = ?"],
        ];
    }

    public function testGetGeneratorLimit()
    {
        $maxCount = 60;
        $chunkSize = 16;

        $data = array();
        for ($i = 0; $i < $maxCount; $i++) {
            $data[] = array($this->generateSystemid(), $i, $i, $i, $i, $i, $i, $i, $i);
        }

        $database = $this->getConnection();
        $database->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE 1=1', []);
        $database->multiInsert(self::TEST_TABLE_NAME, array("temp_id", "temp_bigint", "temp_float", "temp_char10", "temp_char20", "temp_char100", "temp_char254", "temp_char500", "temp_text"), $data);

        $result = $database->getGenerator("SELECT temp_char10 FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC", [], $chunkSize);
        $i = 0;
        $page = 0;
        $pages = floor($maxCount / $chunkSize);
        $rest = $maxCount % $chunkSize;

        foreach ($result as $rows) {
            for ($j = 0; $j < $chunkSize; $j++) {
                if ($page == $pages && $j >= $rest) {
                    $this->assertEquals($rest, count($rows));

                    // if we have reached the last row of the last chunk break
                    break 2;
                }

                $this->assertEquals($i, $rows[$j]["temp_char10"]);
                $i++;
            }

            $this->assertEquals($chunkSize, count($rows));
            $page++;
        }

        $this->assertEquals($maxCount, $i);
        $this->assertEquals($pages, $page);
    }

    public function testGetGeneratorNoPaging()
    {
        $maxCount = 60;
        $chunkSize = 16;

        $data = array();
        for ($i = 0; $i < $maxCount; $i++) {
            $data[] = array($this->generateSystemid(), $i, $i, $i, $i, $i, $i, $i, $i);
        }

        $database = $this->getConnection();
        $database->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE 1=1', []);
        $database->multiInsert(self::TEST_TABLE_NAME, array("temp_id", "temp_bigint", "temp_float", "temp_char10", "temp_char20", "temp_char100", "temp_char254", "temp_char500", "temp_text"), $data);

        $result = $database->getGenerator("SELECT temp_id FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_bigint ASC", [], $chunkSize, false);
        $i = 0;

        foreach ($result as $rows) {
            foreach ($rows as $row) {
                $database->_pQuery("DELETE FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", [$row["temp_id"]]);
                $i++;
            }
        }

        $this->assertEquals($maxCount, $i);
    }

    public function testGetGenerator()
    {
        $connection = $this->getConnection();
        $generator = $connection->getGenerator("SELECT * FROM " . self::TEST_TABLE_NAME . " ORDER BY temp_int ASC", [], 6);

        $this->assertInstanceOf(\Generator::class, $generator);

        $intI = 0;
        $j = 0;
        foreach ($generator as $arrResult) {
            $this->assertEquals($j == 8 ? 2 : 6, count($arrResult));
            foreach ($arrResult as $arrRow) {
                $this->assertEquals("char20-" . ($intI + 1), $arrRow["temp_char20"]);
                $intI++;
            }
            $j++;
        }
        $this->assertEquals(50, $intI);
        $this->assertEquals(9, $j);

        $connection->_pQuery("DROP TABLE " . self::TEST_TABLE_NAME, []);
    }

    public function testInsert()
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        $row = [
            "temp_id" => $systemId,
            "temp_char20" => $this->generateSystemid(),
        ];

        $connection->insert(self::TEST_TABLE_NAME, $row);

        $result = $connection->getPArray("SELECT * FROM " . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', [$systemId]);

        $this->assertSame(1, count($result));
        $this->assertEquals($row["temp_id"], $result[0]["temp_id"]);
        $this->assertEquals($row["temp_char20"], $result[0]["temp_char20"]);
    }

    public function testUpdate()
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        $row = [
            "temp_id" => $systemId,
            "temp_int" => 13,
            "temp_char20" => "foobar",
        ];

        $connection->insert(self::TEST_TABLE_NAME, $row);

        $row = $connection->getPRow("SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", [$systemId], 0, false);

        $this->assertEquals($systemId, $row["temp_id"]);
        $this->assertEquals(13, $row["temp_int"]);
        $this->assertEquals("foobar", $row["temp_char20"]);

        $connection->update(self::TEST_TABLE_NAME, ["temp_int" => 1337, "temp_char20" => "foo"], ["temp_id" => $systemId]);

        $row = $connection->getPRow("SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", [$systemId], 0, false);

        $this->assertEquals($systemId, $row["temp_id"]);
        $this->assertEquals(1337, $row["temp_int"]);
        $this->assertEquals("foo", $row["temp_char20"]);
    }

    public function testDelete()
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        $row = [
            "temp_id" => $systemId,
            "temp_int" => 13,
            "temp_char20" => "foobar",
        ];

        $connection->insert(self::TEST_TABLE_NAME, $row);

        $row = $connection->getPRow("SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", [$systemId], 0, false);

        $this->assertEquals($systemId, $row["temp_id"]);
        $this->assertEquals(13, $row["temp_int"]);
        $this->assertEquals("foobar", $row["temp_char20"]);

        $connection->delete(self::TEST_TABLE_NAME, ["temp_id" => $systemId]);

        $row = $connection->getPRow("SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", [$systemId], 0, false);

        $this->assertEmpty($row);
    }

    /**
     * This test checks whether we can use a long timestamp format in in an sql query
     * @dataProvider intComparisonDataProvider
     */
    public function testIntComparison($strId, $longDate, $longExpected)
    {
        // note calculation does not work if we cross a year border
        $objLeftDate = \DateTime::createFromFormat('YmdHis', '' . $longDate);
        $objLeftDate->add(new \DateInterval('P1M'));
        $left = $objLeftDate->format('YmdHis');

        $objDB = $this->getConnection();
        $objDB->insert(self::TEST_TABLE_NAME, [
            'temp_id' => $strId,
            'temp_bigint' => $longDate,
        ]);

        $strQuery = "SELECT " . $left . " - " . $longDate . " AS result_1, " . $left . " - temp_bigint AS result_2 FROM " . self::TEST_TABLE_NAME . ' WHERE temp_id = ?';
        $arrRow = $objDB->getPRow($strQuery, [$strId]);

        $this->assertEquals($longExpected, $left - $longDate);
        $this->assertEquals($longExpected, $arrRow["result_1"]);
        $this->assertEquals($longExpected, $arrRow["result_2"]);
    }

    public function intComparisonDataProvider()
    {
        return [
            ["a111", 20170801000000, 20170901000000-20170801000000],
            ["a112", 20171101000000, 20171201000000-20171101000000],
            ["a113", 20171201000000, 20180101000000-20171201000000],
            ["a113", 20171215000000, 20180115000000-20171215000000],
            ["a113", 20171230000000, 20180130000000-20171230000000],
            ["a113", 20171231000000, 20180131000000-20171231000000],
            ["a113", 20170101000000, 20170201000000-20170101000000],
        ];
    }

    /**
     * This test checks whether we can safely use CONCAT on all database drivers
     */
    public function testSqlConcat()
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();
        $connection->multiInsert(self::TEST_TABLE_NAME, ["temp_id"], [[$systemId]]);

        $query = "SELECT " . $connection->getConcatExpression(["','", "temp_id", "','"]) . " AS val FROM " . self::TEST_TABLE_NAME . ' WHERE temp_id = ?';
        $row = $connection->getPRow($query, [$systemId]);

        $this->assertEquals(",{$systemId},", $row["val"]);

        $query = "SELECT temp_id as val FROM " . self::TEST_TABLE_NAME . " WHERE " . $connection->getConcatExpression(["','", "temp_id", "','"]) ." LIKE ? ";//. " AS val FROM agp_temp_autotest";
        $row = $connection->getPRow($query, ["%{$systemId}%"]);

        $this->assertEquals($systemId, $row["val"]);
    }

    /**
     * @dataProvider databaseValueProvider
     */
    public function testConvertToDatabaseValue($value, string $type)
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        if ($type === DataType::STR_TYPE_FLOAT) {
            $column = 'temp_float';
        } elseif ($type === DataType::STR_TYPE_BIGINT) {
            $column = 'temp_bigint';
        } else {
            $column = 'temp_' . $type;
        }

        $connection->insert(self::TEST_TABLE_NAME, [
            'temp_id' => $systemId,
            $column => $connection->convertToDatabaseValue($value, $type),
        ]);

        // check whether the data was correctly inserted into the table
        $row = $connection->getPRow('SELECT ' . $column . ' AS val FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', [$systemId]);
        $actual = $row['val'];
        $expect = $value;

        if ($type === DataType::STR_TYPE_CHAR10) {
            $expect = substr($expect, 0, 10);
        } elseif ($type === DataType::STR_TYPE_CHAR20) {
            $expect = substr($expect, 0, 20);
        } elseif ($type === DataType::STR_TYPE_CHAR100) {
            $expect = substr($expect, 0, 100);
        } elseif ($type === DataType::STR_TYPE_CHAR254) {
            $expect = substr($expect, 0, 254);
        } elseif ($type === DataType::STR_TYPE_CHAR500) {
            $expect = substr($expect, 0, 500);
        } elseif ($type === DataType::STR_TYPE_TEXT) {
            if ($connection->hasDriver(Oci8Driver::class)) {
                // for oracle the text column is max 4000 chars
                $expect = substr($expect, 0, 4000);
            }
        } elseif ($type === DataType::STR_TYPE_DOUBLE) {
            $actual = round($actual, 1);
        }

        $this->assertEquals($expect, $actual);
    }

    public function databaseValueProvider()
    {
        return [
            [PHP_INT_MAX, DataType::STR_TYPE_LONG],
            [4, DataType::STR_TYPE_INT],
            [4.8, DataType::STR_TYPE_DOUBLE],
            ['aaa', DataType::STR_TYPE_CHAR10],
            [str_repeat('a', 50), DataType::STR_TYPE_CHAR10],
            ['aaa', DataType::STR_TYPE_CHAR20],
            [str_repeat('a', 50), DataType::STR_TYPE_CHAR20],
            ['aaa', DataType::STR_TYPE_CHAR100],
            [str_repeat('a', 150), DataType::STR_TYPE_CHAR100],
            ['aaa', DataType::STR_TYPE_CHAR254],
            [str_repeat('a', 300), DataType::STR_TYPE_CHAR254],
            ['aaa', DataType::STR_TYPE_CHAR500],
            [str_repeat('a', 600), DataType::STR_TYPE_CHAR500],
            ['aaa', DataType::STR_TYPE_TEXT],
            [str_repeat('a', 4010), DataType::STR_TYPE_TEXT],
            ['aaa', DataType::STR_TYPE_LONGTEXT],
        ];
    }

    /**
     * This test checks whether LEAST() Expression is working on all databases (Sqlite uses MIN() instead)
     */
    public function testLeastExpressionWorksOnAllDatabases()
    {
        $connection = $this->getConnection();

        $tableName = 'agp_test_least';
        $fields = [
            'test_id' => [DataType::STR_TYPE_CHAR20, false],
            'column_1'  => [DataType::STR_TYPE_INT, true],
            'column_2'  => [DataType::STR_TYPE_INT, true],
            'column_3'  => [DataType::STR_TYPE_INT, true],
            'column_4'  => [DataType::STR_TYPE_CHAR20, true],
            'column_5'  => [DataType::STR_TYPE_CHAR20, true],
            'column_6'  => [DataType::STR_TYPE_CHAR20, true],
        ];

        $connection->createTable($tableName, $fields, ['test_id']);

        $testData = [
            ['test_id' => 'abc', 'column_1' => -1, 'column_2' => 0, 'column_3' => 1, 'column_4' => null, 'column_5' => null, 'column_6' => null],
            ['test_id' => 'xyz', 'column_1' => 10, 'column_2' => 100, 'column_3' => 1000, 'column_4' => null, 'column_5' => null, 'column_6' => null],
            ['test_id' => 'foo', 'column_1' => null, 'column_2' => null, 'column_3' => null, 'column_4' => 'alpha', 'column_5' => 'beta', 'column_6' => 'gamma'],
            ['test_id' => 'bar', 'column_1' => null, 'column_2' => null, 'column_3' => null, 'column_4' => 'lorem', 'column_5' => 'ipsum', 'column_6' => 'dolor'],
        ];

        $connection->multiInsert($tableName, array_keys($fields), $testData);

        $testCases = [
            ['conditionValue' => 'abc', 'columns' => ['column_1', 'column_2', 'column_3'], 'expectedResult' => -1],
            ['conditionValue' => 'xyz', 'columns' => ['column_1', 'column_2', 'column_3'], 'expectedResult' => 10],
            ['conditionValue' => 'foo', 'columns' => ['column_4', 'column_5', 'column_6'], 'expectedResult' => 'alpha'],
            ['conditionValue' => 'bar', 'columns' => ['column_4', 'column_5', 'column_6'], 'expectedResult' => 'dolor'],
        ];


        foreach ($testCases as $testCase) {
            $query = 'SELECT ' . $connection->getLeastExpression($testCase['columns']) . ' AS minimum FROM ' . $tableName . ' WHERE test_id = ?';
            $row = $connection->getPRow($query, [$testCase['conditionValue']]);

            $this->assertEquals($testCase['expectedResult'], $row['minimum']);
        }

        $connection->_pQuery('DROP TABLE ' . $tableName, []);
    }

    public function testAllowsRetrievalOfSubstringsIndependentOfPlatform(): void
    {
        $connection = $this->getConnection();

        $connection->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME);
        $connection->_pQuery(
            'INSERT INTO ' . self::TEST_TABLE_NAME . ' (temp_id, temp_char100) VALUES (?, ?)',
            [$this->generateSystemid(), 'foobarbazquux']
        );

        $testCases = [
            [1, 3, 'foo'],
            [1, 6, 'foobar'],
            [1, null, 'foobarbazquux'],
            [4, 3, 'bar'],
            [4, null, 'barbazquux'],
            [7, 3, 'baz'],
            [7, null, 'bazquux'],
            [10, 4, 'quux'],
            [10, null, 'quux'],
            [1, null, 'foobarbazquux'],
        ];

        foreach ($testCases as [$offset, $length, $expectedValue]) {
            $substringExpression = $connection->getSubstringExpression('temp_char100', $offset, $length);
            ['value' => $actualValue] = $connection->getPRow('SELECT ' . $substringExpression . ' AS value FROM ' . self::TEST_TABLE_NAME);
            self::assertEquals($expectedValue, $actualValue);
        }
    }

    public function testHasTable(){
        $tableName = self::TEST_TABLE_NAME;
        $connection = $this->getConnection();
        $this->assertTrue($connection->hasTable($tableName));
        $this->assertFalse($connection->hasTable('table_does_not_exist'));
    }
}

