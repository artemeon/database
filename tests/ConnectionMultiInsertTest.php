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

class ConnectionMultiInsertTest extends ConnectionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->getConnection()->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE 1=1', []);
        $this->getConnection()->flushQueryCache();
    }

    public function testInserts()
    {
        $values = $this->getRows(50);
        $connection = $this->getConnection();

        $return = $connection->multiInsert(self::TEST_TABLE_NAME, $this->getColumnNames(), $values);
        $this->assertTrue($return);

        $row = $connection->getPRow("SELECT COUNT(*) AS cnt FROM " . self::TEST_TABLE_NAME, []);
        $this->assertEquals($row['cnt'], 50);

        for ($i = 1; $i <= 50; $i++) {
            $row = $connection->getPRow("SELECT * FROM " . self::TEST_TABLE_NAME . " WHERE temp_id = ?", ["id" . $i]);

            $this->assertEquals(10, $row["temp_int"]);
            $this->assertEquals(13, $row["temp_bigint"]);
            $this->assertEquals(13.37, round($row["temp_float"], 2));
            $this->assertEquals("char10", $row["temp_char10"]);
            $this->assertEquals("char20", $row["temp_char20"]);
            $this->assertEquals("char100", $row["temp_char100"]);
            $this->assertEquals("char254", $row["temp_char254"]);
            $this->assertEquals("char500", $row["temp_char500"]);
            $this->assertEquals("text", $row["temp_text"]);
            $this->assertEquals("longtext", $row["temp_longtext"]);
        }
    }

    public function testInsertsLimit()
    {
        $values = $this->getRows(1000);
        $connection = $this->getConnection();

        $return = $connection->multiInsert(self::TEST_TABLE_NAME, $this->getColumnNames(), $values);
        $this->assertTrue($return);

        $arrRow = $connection->getPRow("SELECT COUNT(*) AS cnt FROM " . self::TEST_TABLE_NAME, []);
        $this->assertEquals($arrRow["cnt"], 1000);
    }

    protected function getColumnNames(): array
    {
        return [
            'temp_id',
            'temp_int',
            'temp_bigint',
            'temp_float',
            'temp_char10',
            'temp_char20',
            'temp_char100',
            'temp_char254',
            'temp_char500',
            'temp_text',
            'temp_longtext',
        ];
    }

    protected function getRows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                "id" . $i,
                10,
                13,
                13.37,
                "char10",
                "char20",
                "char100",
                "char254",
                "char500",
                "text",
                "longtext",
            ];
        }

        return $rows;
    }
}
