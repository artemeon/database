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

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $row = $connection->getPRow($query);
        $this->assertTrue(count($row) >= 9, 'testDataBase getRow count');
        $this->assertEquals('char10-1', $row['temp_char10'], 'testDataBase getRow content');


        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char10 = ? ORDER BY temp_bigint ASC';
        $row = $connection->getPRow($query, ['char10-2']);
        $this->assertTrue(count($row) >= 9, 'testDataBase getRow count');
        $this->assertEquals('char10-2', $row['temp_char10'], 'testDataBase getRow content');

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $row = $connection->getPArray($query);
        $this->assertCount(50, $row, 'testDataBase getArray count');

        $i = 1;
        foreach ($row as $singleRow) {
            $this->assertEquals('char10-' . $i++, $singleRow['temp_char10'], 'testDataBase getArray content');
        }

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char10 = ? ORDER BY temp_bigint ASC';
        $row = $connection->getPArray($query, ['char10-2']);
        $this->assertCount(1, $row, 'testDataBase getArray count');

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $row = $connection->getPArray($query, [], 0, 9);
        $this->assertCount(10, $row, 'testDataBase getArraySection count');
        $this->assertEquals('char10-1', $row[0]['temp_char10']);
        $this->assertEquals('char10-10', $row[9]['temp_char10']);

        $i = 1;
        foreach ($row as $singleRow) {
            $this->assertEquals('char10-' . $i++, $singleRow['temp_char10'], 'testDataBase getArraySection content');
        }

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $row = $connection->getPArray($query, [], 5, 14);
        $this->assertCount(10, $row, 'testDataBase getArraySection offset count');
        $this->assertEquals('char10-6', $row[0]['temp_char10']);
        $this->assertEquals('char10-15', $row[9]['temp_char10']);

        $this->flushDBCache();
        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char10 LIKE ? ORDER BY temp_bigint ASC';
        $row = $connection->getPArray($query, ['%'], 0, 9);
        $this->assertCount(10, $row, 'testDataBase getArraySection param count');

        $i = 1;
        foreach ($row as $singleRow) {
            $this->assertEquals('char10-' . $i++, $singleRow['temp_char10'], 'testDataBase getArraySection param content');
        }

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . '  WHERE temp_char10 = ? AND temp_char20 = ? ORDER BY temp_bigint ASC';
        $row = $connection->getPArray($query, ['char10-2', 'char202']);
        $this->assertCount(0, $row, 'testDataBase getArray 2 params count');

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . '  WHERE temp_char10 = ? AND temp_char20 = ? ORDER BY temp_bigint ASC';
        $row = $connection->getPArray($query, ['2', null]);
        $this->assertCount(0, $row, 'testDataBase getArray 2 params count');

        $query = 'DROP TABLE ' . self::TEST_TABLE_NAME;
        $this->assertTrue($connection->_pQuery($query), 'testDataBase dropTable');
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testFloatHandling(): void
    {
        $connection = $this->getConnection();

        $columns = [
            'temp_id' => [DataType::CHAR20, false],
            'temp_long' => [DataType::BIGINT, true],
            'temp_double' => [DataType::FLOAT, true],
        ];

        $this->assertTrue($connection->createTable('agp_temp_autotest_float', $columns, ['temp_id']), 'testDataBase createTable');

        $connection->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE 1 = 1');

        $connection->multiInsert(
            self::TEST_TABLE_NAME,
            ['temp_id', 'temp_bigint', 'temp_float'],
            [['id1', 123456, 1.7], ['id2', '123456', '1.7']]
        );

        $row = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', ['id1']);

        $this->assertEquals(123456, $row['temp_bigint']);
        $this->assertEquals(1.7, round((float) $row['temp_float'], 1));

        $row = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', ['id2']);

        $this->assertEquals(123456, $row['temp_bigint']);
        $this->assertEquals(1.7, round((float) $row['temp_float'], 1));
    }
}

