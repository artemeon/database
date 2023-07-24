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
use Artemeon\Database\Exception\AddColumnException;
use Artemeon\Database\Exception\ChangeColumnException;
use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Exception\RemoveColumnException;
use Artemeon\Database\Schema\DataType;
use DateInterval;
use DateTime;
use ReflectionClass;

class ConnectionTest extends ConnectionTestCase
{
    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testRenameTable(): void
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

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testCreateIndex(): void
    {
        $connection = $this->getConnection();

        $result = $connection->createIndex(self::TEST_TABLE_NAME, 'foo_index', ['temp_char10', 'temp_char20']);

        $this->assertTrue($result);
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testCreateUniqueIndex(): void
    {
        $connection = $this->getConnection();

        $output = $connection->createIndex(self::TEST_TABLE_NAME, 'foo_index', ['temp_char10', 'temp_char20'], true);

        $this->assertTrue($output);
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testHasIndex(): void
    {
        $connection = $this->getConnection();

        $this->assertFalse($connection->hasIndex(self::TEST_TABLE_NAME, 'foo_index'));

        $output = $connection->createIndex(self::TEST_TABLE_NAME, 'foo_index', ['temp_char10', 'temp_char20']);

        $this->assertTrue($connection->hasIndex(self::TEST_TABLE_NAME, 'foo_index'));
        $this->assertTrue($output);
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testDropIndex(): void
    {
        $connection = $this->getConnection();

        $this->assertFalse($connection->hasIndex(self::TEST_TABLE_NAME, 'foo_index2'));
        $this->assertTrue($connection->createIndex(self::TEST_TABLE_NAME, 'foo_index2', ['temp_char10', 'temp_char20']));
        $this->assertTrue($connection->hasIndex(self::TEST_TABLE_NAME, 'foo_index2'));

        $this->assertTrue($connection->deleteIndex(self::TEST_TABLE_NAME, 'foo_index2'));
        $this->assertFalse($connection->hasIndex(self::TEST_TABLE_NAME, 'foo_index2'));
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testFloatHandling(): void
    {
        $connection = $this->getConnection();

        $connection->insert(self::TEST_TABLE_NAME, ['temp_id' => 'id1', 'temp_float' => 16.8]);
        $connection->insert(self::TEST_TABLE_NAME, ['temp_id' => 'id2', 'temp_float' => 1000.8]);

        $row = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' where temp_id = ?', ['id1']);
        // MSSQL returns 16.799999237061 instead of 16.8
        $this->assertEquals(16.8, round((float) $row['temp_float'], 1));
        $this->assertEquals('16.8', round((float) $row['temp_float'], 1));

        $row = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' where temp_id = ?', ['id2']);
        $this->assertEquals(1000.8, round((float) $row['temp_float'], 1));
        $this->assertEquals('1000.8', round((float) $row['temp_float'], 1));
    }

    /**
     * @throws ChangeColumnException
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testChangeColumn(): void
    {
        $connection = $this->getConnection();

        $connection->insert(self::TEST_TABLE_NAME, ['temp_id' => 'aaa', 'temp_int' => 111]);
        $connection->insert(self::TEST_TABLE_NAME, ['temp_id' => 'bbb', 'temp_int' => 222]);

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_id'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_int'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_bigint'));

        $this->assertTrue($connection->changeColumn(self::TEST_TABLE_NAME, 'temp_int', 'temp_bigint_new', DataType::BIGINT));

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_id'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_int'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_bigint_new'));

        $row = $connection->getPRow('SELECT temp_id, temp_bigint_new FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', ['aaa']);
        $this->assertEquals('aaa', $row['temp_id']);
        $this->assertEquals(111, $row['temp_bigint_new']);

        $row = $connection->getPRow('SELECT temp_id, temp_bigint_new FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', ['bbb']);
        $this->assertEquals('bbb', $row['temp_id']);
        $this->assertEquals(222, $row['temp_bigint_new']);
    }

    /**
     * @throws ConnectionException
     * @throws ChangeColumnException
     */
    public function testChangeColumnType(): void
    {
        $connection = $this->getConnection();

        // test changing a column type with the same column name
        $this->assertTrue($connection->changeColumn(self::TEST_TABLE_NAME, 'temp_char500', 'temp_char500', DataType::CHAR10));
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     * @throws AddColumnException
     */
    public function testAddColumn(): void
    {
        $connection = $this->getConnection();

        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col1'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col2'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col3'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col4'));

        $this->assertTrue($connection->addColumn(self::TEST_TABLE_NAME, 'temp_new_col1', DataType::INT));
        $this->assertTrue($connection->addColumn(self::TEST_TABLE_NAME, 'temp_new_col2', DataType::INT, true, 'NULL'));
        $this->assertTrue($connection->addColumn(self::TEST_TABLE_NAME, 'temp_new_col3', DataType::INT, false, '0'));
        $this->assertTrue($connection->addColumn(self::TEST_TABLE_NAME, 'temp_new_col4', DataType::INT, true));

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col1'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col2'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col3'));
        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_new_col4'));
    }

    /**
     * @throws QueryException
     */
    public function testHasColumn(): void
    {
        $connection = $this->getConnection();

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_id'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_foo'));
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     * @throws RemoveColumnException
     */
    public function testRemoveColumn(): void
    {
        $connection = $this->getConnection();

        $this->assertTrue($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_bigint'));
        $this->assertTrue($connection->removeColumn(self::TEST_TABLE_NAME, 'temp_bigint'));
        $this->assertFalse($connection->hasColumn(self::TEST_TABLE_NAME, 'temp_bigint'));
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testCreateTable(): void
    {
        $connection = $this->getConnection();

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $row = $connection->getPRow($query);
        $this->assertTrue(count($row) >= 9, 'testDataBase getRow count');

        $this->assertEquals('20200508095301', $row['temp_bigint'], 'testDataBase getRow content');
        $this->assertEquals(23.45, round((float) $row['temp_float'], 2), 'testDataBase getRow content');
        $this->assertEquals('char10-1', $row['temp_char10'], 'testDataBase getRow content');
        $this->assertEquals('char20-1', $row['temp_char20'], 'testDataBase getRow content');
        $this->assertEquals('char100-1', $row['temp_char100'], 'testDataBase getRow content');
        $this->assertEquals('char254-1', $row['temp_char254'], 'testDataBase getRow content');
        $this->assertEquals('char500-1', $row['temp_char500'], 'testDataBase getRow content');
        $this->assertEquals('text-1', $row['temp_text'], 'testDataBase getRow content');

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $row = $connection->getPArray($query);
        $this->assertCount(50, $row, 'testDataBase getArray count');

        $i = 1;
        foreach ($row as $singleRow) {
            $this->assertEquals('char10-' . $i, $singleRow['temp_char10'], 'testDataBase getArray content');
            $i++;
        }

        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC';
        $row = $connection->getPArray($query, [], 0, 9);
        $this->assertCount(10, $row, 'testDataBase getArraySection count');

        $i = 1;
        foreach ($row as $singleRow) {
            $this->assertEquals('char10-' . $i, $singleRow['temp_char10'], 'testDataBase getArraySection content');
            $i++;
        }
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testCreateTableIndex(): void
    {
        $connection = $this->getConnection();

        $columns = [
            'temp_id' => [DataType::CHAR20, false],
            'temp_bigint' => [DataType::BIGINT, true],
            'temp_float' => [DataType::FLOAT, true],
            'temp_char10' => [DataType::CHAR10, true],
            'temp_char20' => [DataType::CHAR20, true],
            'temp_char100' => [DataType::CHAR100, true],
            'temp_char254' => [DataType::CHAR254, true],
            'temp_char500' => [DataType::CHAR500, true],
            'temp_text' => [DataType::TEXT, true],
        ];

        $this->assertTrue($connection->createTable('agp_temp_autotest', $columns, ['temp_id'], [['temp_id', 'temp_char10', 'temp_char100'], 'temp_char254']), 'testDataBase createTable');
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testEscapeText(): void
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
        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char20 LIKE ?';
        $row = $connection->getPRow($query, [$connection->escape("Foo\\Bar%")]);

        $this->assertNotEmpty($row);
        $this->assertEquals('Foo\\Bar\\Baz', $row['temp_char20']);

        // equals needs no escape
        $query = 'SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char20 = ?';
        $row = $connection->getPRow($query, ["Foo\\Bar\\Baz"]);

        $this->assertNotEmpty($row);
        $this->assertEquals('Foo\\Bar\\Baz', $row['temp_char20']);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testGetPArray(): void
    {
        $connection = $this->getConnection();

        $result = $connection->getPArray('SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC', [], 0, 0);
        $this->assertCount(1, $result);
        $this->assertEquals(20200508095301, $result[0]['temp_bigint']);
        $result = $connection->getPArray('SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC', [], 0, 7);
        $this->assertCount(8, $result);
        for ($i = 0; $i < 8; $i++) {
            $this->assertEquals(20200508095301 + $i, $result[$i]['temp_bigint']);
        }

        $result = $connection->getPArray('SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC', [], 4, 7);
        $this->assertCount(4, $result);
        for ($i = 4; $i < 8; $i++) {
            $this->assertEquals(20200508095301 + $i, $result[$i - 4]['temp_bigint']);
        }
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testGetAffectedRows(): void
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        // insert, which affects one row
        $connection->multiInsert(self::TEST_TABLE_NAME,
            ['temp_id', 'temp_char20'],
            [[$this->generateSystemid(), $systemId]],
        );
        $this->assertEquals(1, $connection->getAffectedRowsCount());

        // insert, which affects two rows
        $connection->multiInsert(self::TEST_TABLE_NAME,
            ['temp_id', 'temp_char20'],
            [
                [$this->generateSystemid(), $systemId],
                [$this->generateSystemid(), $systemId],
            ],
        );
        $this->assertEquals(2, $connection->getAffectedRowsCount());

        $newSystemId = $this->generateSystemid();

        // update, which affects multiple rows
        $connection->_pQuery('UPDATE ' . self::TEST_TABLE_NAME . ' SET temp_char20 = ? WHERE temp_char20 = ?', [$newSystemId, $systemId]);
        $this->assertEquals(3, $connection->getAffectedRowsCount());

        // update, which does not affect a row
        $connection->_pQuery('UPDATE ' . self::TEST_TABLE_NAME . ' SET temp_char20 = ? WHERE temp_char20 = ?', [$this->generateSystemid(), $this->generateSystemid()]);
        $this->assertEquals(0, $connection->getAffectedRowsCount());

        // delete, which affects two rows
        $connection->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char20 = ?', [$newSystemId]);
        $this->assertEquals(3, $connection->getAffectedRowsCount());

        // delete, which affects no rows
        $connection->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char20 = ?', [$this->generateSystemid()]);
        $this->assertEquals(0, $connection->getAffectedRowsCount());
    }

    /**
     * @dataProvider dataPostgresProcessQueryProvider
     * @covers       \Artemeon\Database\Driver\PostgresDriver::processQuery
     * @throws \ReflectionException
     */
    public function testPostgresProcessQuery($expected, $query): void
    {
        $dbPostgres = new PostgresDriver();
        $reflection = new ReflectionClass(PostgresDriver::class);

        $method = $reflection->getMethod('processQuery');

        $method->setAccessible(true);
        $actual = $method->invoke($dbPostgres, $query);

        $this->assertEquals($expected, $actual);
    }

    public function dataPostgresProcessQueryProvider(): array
    {
        return [
            ['UPDATE temp_autotest_temp SET temp_char20 = $1 WHERE temp_char20 = $2', 'UPDATE temp_autotest_temp SET temp_char20 = ? WHERE temp_char20 = ?'],
            ["INSERT INTO temp_autotest (temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text) VALUES ($1, $2, $3, $4, $5, $6),\n($7, $8, $9, $10, $11, $12)", "INSERT INTO temp_autotest (temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text) VALUES (?, ?, ?, ?, ?, ?),\n(?, ?, ?, ?, ?, ?)"],
            ['SELECT * FROM temp_autotest WHERE temp_char10 = $1 AND temp_char20 = $2 AND temp_char100 = $3', 'SELECT * FROM temp_autotest WHERE temp_char10 = ? AND temp_char20 = ? AND temp_char100 = ?'],
        ];
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testGetGeneratorLimit(): void
    {
        $maxCount = 60;
        $chunkSize = 16;

        $data = [];
        for ($i = 0; $i < $maxCount; $i++) {
            $data[] = [$this->generateSystemid(), $i, $i, $i, $i, $i, $i, $i, $i];
        }

        $database = $this->getConnection();
        $database->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE 1=1', []);
        $database->multiInsert(self::TEST_TABLE_NAME, [
            'temp_id',
            'temp_bigint',
            'temp_float',
            'temp_char10',
            'temp_char20',
            'temp_char100',
            'temp_char254',
            'temp_char500',
            'temp_text',
        ], $data);

        $result = $database->getGenerator('SELECT temp_char10 FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC', [], $chunkSize);
        $i = 0;
        $page = 0;
        $pages = floor($maxCount / $chunkSize);
        $rest = $maxCount % $chunkSize;

        foreach ($result as $rows) {
            for ($j = 0; $j < $chunkSize; $j++) {
                if ($page == $pages && $j >= $rest) {
                    $this->assertCount($rest, $rows);

                    // if we have reached the last row of the last chunk break.
                    break 2;
                }

                $this->assertEquals($i, $rows[$j]['temp_char10']);
                $i++;
            }

            $this->assertCount($chunkSize, $rows);
            $page++;
        }

        $this->assertEquals($maxCount, $i);
        $this->assertEquals($pages, $page);
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testGetGeneratorNoPaging(): void
    {
        $maxCount = 60;
        $chunkSize = 16;

        $data = [];
        for ($i = 0; $i < $maxCount; $i++) {
            $data[] = [$this->generateSystemid(), $i, $i, $i, $i, $i, $i, $i, $i];
        }

        $database = $this->getConnection();
        $database->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE 1=1');
        $database->multiInsert(self::TEST_TABLE_NAME, [
            'temp_id',
            'temp_bigint',
            'temp_float',
            'temp_char10',
            'temp_char20',
            'temp_char100',
            'temp_char254',
            'temp_char500',
            'temp_text',
        ], $data);

        $result = $database->getGenerator('SELECT temp_id FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_bigint ASC', [], $chunkSize, false);
        $i = 0;

        foreach ($result as $rows) {
            foreach ($rows as $row) {
                $database->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', [$row['temp_id']]);
                $i++;
            }
        }

        $this->assertEquals($maxCount, $i);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testGetGenerator(): void
    {
        $connection = $this->getConnection();
        $generator = $connection->getGenerator('SELECT * FROM ' . self::TEST_TABLE_NAME . ' ORDER BY temp_int ASC', [], 6);

        $i = 0;
        $j = 0;
        foreach ($generator as $result) {
            $this->assertCount($j === 8 ? 2 : 6, $result);
            foreach ($result as $row) {
                $this->assertEquals('char20-' . ($i + 1), $row['temp_char20']);
                $i++;
            }
            $j++;
        }
        $this->assertEquals(50, $i);
        $this->assertEquals(9, $j);

        $connection->_pQuery('DROP TABLE ' . self::TEST_TABLE_NAME);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testInsert(): void
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        $row = [
            'temp_id' => $systemId,
            'temp_char20' => $this->generateSystemid(),
        ];

        $connection->insert(self::TEST_TABLE_NAME, $row);

        $result = $connection->getPArray('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', [$systemId]);

        $this->assertCount(1, $result);
        $this->assertEquals($row['temp_id'], $result[0]['temp_id']);
        $this->assertEquals($row['temp_char20'], $result[0]['temp_char20']);
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testUpdate(): void
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        $row = [
            'temp_id' => $systemId,
            'temp_int' => 13,
            'temp_char20' => 'foobar',
        ];

        $connection->insert(self::TEST_TABLE_NAME, $row);

        $row = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', [$systemId], 0, false);

        $this->assertEquals($systemId, $row['temp_id']);
        $this->assertEquals(13, $row['temp_int']);
        $this->assertEquals('foobar', $row['temp_char20']);

        $connection->update(self::TEST_TABLE_NAME, ['temp_int' => 1337, 'temp_char20' => 'foo'], ['temp_id' => $systemId]);

        $row = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', [$systemId], 0, false);

        $this->assertEquals($systemId, $row['temp_id']);
        $this->assertEquals(1337, $row['temp_int']);
        $this->assertEquals('foo', $row['temp_char20']);
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testDelete(): void
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        $row = [
            'temp_id' => $systemId,
            'temp_int' => 13,
            'temp_char20' => 'foobar',
        ];

        $connection->insert(self::TEST_TABLE_NAME, $row);

        $row = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', [$systemId], 0, false);

        $this->assertEquals($systemId, $row['temp_id']);
        $this->assertEquals(13, $row['temp_int']);
        $this->assertEquals('foobar', $row['temp_char20']);

        $connection->delete(self::TEST_TABLE_NAME, ['temp_id' => $systemId]);

        $row = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', [$systemId], 0, false);

        $this->assertEmpty($row);
    }

    /**
     * This test checks whether we can use a long timestamp format in in an sql query.
     * @dataProvider intComparisonDataProvider
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testIntComparison($id, $date, $expected): void
    {
        // note calculation does not work if we cross a year border.
        $objLeftDate = DateTime::createFromFormat('YmdHis', '' . $date);
        $objLeftDate->add(new DateInterval('P1M'));
        $left = $objLeftDate->format('YmdHis');

        $objDB = $this->getConnection();
        $objDB->insert(self::TEST_TABLE_NAME, [
            'temp_id' => $id,
            'temp_bigint' => $date,
        ]);

        $query = 'SELECT ' . $left . ' - ' . $date . ' AS result_1, ' . $left . ' - temp_bigint AS result_2 FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?';
        $row = $objDB->getPRow($query, [$id]);

        $this->assertEquals($expected, $left - $date);
        $this->assertEquals($expected, $row['result_1']);
        $this->assertEquals($expected, $row['result_2']);
    }

    public function intComparisonDataProvider(): array
    {
        return [
            ['a111', 20170801000000, 20170901000000-20170801000000],
            ['a112', 20171101000000, 20171201000000-20171101000000],
            ['a113', 20171201000000, 20180101000000-20171201000000],
            ['a113', 20171215000000, 20180115000000-20171215000000],
            ['a113', 20171230000000, 20180130000000-20171230000000],
            ['a113', 20171231000000, 20180131000000-20171231000000],
            ['a113', 20170101000000, 20170201000000-20170101000000],
        ];
    }

    /**
     * This test checks whether we can safely use CONCAT on all database drivers.
     *
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testSqlConcat(): void
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();
        $connection->multiInsert(self::TEST_TABLE_NAME, ['temp_id'], [[$systemId]]);

        $query = 'SELECT ' . $connection->getConcatExpression(["','", 'temp_id', "','"]) . ' AS val FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?';
        $row = $connection->getPRow($query, [$systemId]);

        $this->assertEquals(",$systemId,", $row['val']);

        $query = 'SELECT temp_id as val FROM ' . self::TEST_TABLE_NAME . ' WHERE ' . $connection->getConcatExpression(["','", 'temp_id', "','"]) . ' LIKE ? ';//. " AS val FROM agp_temp_autotest";
        $row = $connection->getPRow($query, ["%$systemId%"]);

        $this->assertEquals($systemId, $row['val']);
    }

    /**
     * @dataProvider databaseValueProvider
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testConvertToDatabaseValue($value, DataType $type): void
    {
        $connection = $this->getConnection();
        $systemId = $this->generateSystemid();

        if ($type === DataType::FLOAT) {
            $column = 'temp_float';
        } elseif ($type === DataType::BIGINT) {
            $column = 'temp_bigint';
        } else {
            $column = 'temp_' . $type->value;
        }

        $connection->insert(self::TEST_TABLE_NAME, [
            'temp_id' => $systemId,
            $column => $connection->convertToDatabaseValue($value, $type),
        ]);

        // check whether the data was correctly inserted into the table
        $row = $connection->getPRow('SELECT ' . $column . ' AS val FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_id = ?', [$systemId]);
        $actual = $row['val'];
        $expect = $value;

        if ($type === DataType::CHAR10) {
            $expect = substr($expect, 0, 10);
        } elseif ($type === DataType::CHAR20) {
            $expect = substr($expect, 0, 20);
        } elseif ($type === DataType::CHAR100) {
            $expect = substr($expect, 0, 100);
        } elseif ($type === DataType::CHAR254) {
            $expect = substr($expect, 0, 254);
        } elseif ($type === DataType::CHAR500) {
            $expect = substr($expect, 0, 500);
        } elseif ($type === DataType::TEXT) {
            if ($connection->hasDriver(Oci8Driver::class)) {
                // for Oracle the text column is max 4000 chars
                $expect = substr($expect, 0, 4000);
            }
        } elseif ($type === DataType::FLOAT) {
            $actual = round((float) $actual, 1);
        }

        $this->assertEquals($expect, $actual);
    }

    public function databaseValueProvider(): array
    {
        return [
            [PHP_INT_MAX, DataType::BIGINT],
            [4, DataType::INT],
            [4.8, DataType::FLOAT],
            ['aaa', DataType::CHAR10],
            [str_repeat('a', 50), DataType::CHAR10],
            ['aaa', DataType::CHAR20],
            [str_repeat('a', 50), DataType::CHAR20],
            ['aaa', DataType::CHAR100],
            [str_repeat('a', 150), DataType::CHAR100],
            ['aaa', DataType::CHAR254],
            [str_repeat('a', 300), DataType::CHAR254],
            ['aaa', DataType::CHAR500],
            [str_repeat('a', 600), DataType::CHAR500],
            ['aaa', DataType::TEXT],
            [str_repeat('a', 4010), DataType::TEXT],
            ['aaa', DataType::LONGTEXT],
        ];
    }

    /**
     * This test checks whether LEAST() Expression is working on all databases (Sqlite uses MIN() instead).
     *
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testLeastExpressionWorksOnAllDatabases(): void
    {
        $connection = $this->getConnection();

        $tableName = 'agp_test_least';
        $fields = [
            'test_id' => [DataType::CHAR20, false],
            'column_1'  => [DataType::INT, true],
            'column_2'  => [DataType::INT, true],
            'column_3'  => [DataType::INT, true],
            'column_4'  => [DataType::CHAR20, true],
            'column_5'  => [DataType::CHAR20, true],
            'column_6'  => [DataType::CHAR20, true],
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

        $connection->_pQuery('DROP TABLE ' . $tableName);
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
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

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testHasTable(): void
    {
        $tableName = self::TEST_TABLE_NAME;
        $connection = $this->getConnection();
        $this->assertTrue($connection->hasTable($tableName));
        $this->assertFalse($connection->hasTable('table_does_not_exist'));
    }
}
