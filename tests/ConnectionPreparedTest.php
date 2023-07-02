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

use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Schema\DataType;

class ConnectionPreparedTest extends ConnectionTestCase
{
    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function test(): void
    {
        $connection = $this->getConnection();

        $strQuery = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $arrRow = $connection->getPRow($strQuery);
        $this->assertTrue(count($arrRow) >= 9, 'testDataBase getRow count');
        $this->assertEquals('char10-1', $arrRow['temp_char10'], 'testDataBase getRow content');


        $strQuery = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char10 = ? ORDER BY temp_bigint ASC';
        $arrRow = $connection->getPRow($strQuery, ['char10-2']);
        $this->assertTrue(count($arrRow) >= 9, 'testDataBase getRow count');
        $this->assertEquals('char10-2', $arrRow['temp_char10'], 'testDataBase getRow content');

        $strQuery = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $arrRow = $connection->getPArray($strQuery);
        $this->assertCount(50, $arrRow, 'testDataBase getArray count');

        $intI = 1;
        foreach ($arrRow as $arrSingleRow) {
            $this->assertEquals('char10-' . $intI++, $arrSingleRow['temp_char10'], 'testDataBase getArray content');
        }

        $strQuery = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char10 = ? ORDER BY temp_bigint ASC';
        $arrRow = $connection->getPArray($strQuery, ['char10-2']);
        $this->assertCount(1, $arrRow, 'testDataBase getArray count');

        $strQuery = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $arrRow = $connection->getPArray($strQuery, [], 0, 9);
        $this->assertCount(10, $arrRow, 'testDataBase getArraySection count');
        $this->assertEquals('char10-1', $arrRow[0]['temp_char10']);
        $this->assertEquals('char10-10', $arrRow[9]['temp_char10']);

        $intI = 1;
        foreach ($arrRow as $arrSingleRow) {
            $this->assertEquals('char10-' . $intI++, $arrSingleRow['temp_char10'], 'testDataBase getArraySection content');
        }

        $strQuery = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $arrRow = $connection->getPArray($strQuery, [], 5, 14);
        $this->assertCount(10, $arrRow, 'testDataBase getArraySection offset count');
        $this->assertEquals('char10-6', $arrRow[0]['temp_char10']);
        $this->assertEquals('char10-15', $arrRow[9]['temp_char10']);

        $this->flushDBCache();
        $strQuery = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char10 LIKE ? ORDER BY temp_bigint ASC';
        $arrRow = $connection->getPArray($strQuery, ['%'], 0, 9);
        $this->assertCount(10, $arrRow, 'testDataBase getArraySection param count');

        $intI = 1;
        foreach ($arrRow as $arrSingleRow) {
            $this->assertEquals('char10-' . $intI++, $arrSingleRow['temp_char10'], 'testDataBase getArraySection param content');
        }

        $strQuery = 'SELECT * FROM ' . self::TEST_TABLE_NAME . '  WHERE temp_char10 = ? AND temp_char20 = ? ORDER BY temp_bigint ASC';
        $arrRow = $connection->getPArray($strQuery, ['char10-2', 'char202']);
        $this->assertCount(0, $arrRow, 'testDataBase getArray 2 params count');

        $strQuery = 'SELECT * FROM ' . self::TEST_TABLE_NAME . '  WHERE temp_char10 = ? AND temp_char20 = ? ORDER BY temp_bigint ASC';
        $arrRow = $connection->getPArray($strQuery, ['2', null]);
        $this->assertCount(0, $arrRow, 'testDataBase getArray 2 params count');

        $strQuery = 'DROP TABLE ' . self::TEST_TABLE_NAME;
        $this->assertTrue($connection->_pQuery($strQuery), 'testDataBase dropTable');
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testFloatHandling(): void
    {
        $connection = $this->getConnection();

        $arrFields = [];
        $arrFields['temp_id'] = [DataType::CHAR20, false];
        $arrFields['temp_long'] = [DataType::BIGINT, true];
        $arrFields['temp_double'] = [DataType::FLOAT, true];

        $this->assertTrue($connection->createTable('agp_temp_autotest_float', $arrFields, ['temp_id']), 'testDataBase createTable');

        $connection->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE 1 = 1');

        $connection->multiInsert(
            self::TEST_TABLE_NAME,
            ['temp_id', 'temp_bigint', 'temp_float'],
            [['id1', 123456, 1.7], ['id2', '123456', '1.7']]
        );

        $arrRow = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', ['id1']);

        $this->assertEquals(123456, $arrRow['temp_bigint']);
        $this->assertEquals(1.7, round((float) $arrRow['temp_float'], 1));

        $arrRow = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', ['id2']);

        $this->assertEquals(123456, $arrRow['temp_bigint']);
        $this->assertEquals(1.7, round((float) $arrRow['temp_float'], 1));
    }
}

