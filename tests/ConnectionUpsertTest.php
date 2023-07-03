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

class ConnectionUpsertTest extends ConnectionTestCase
{
    public function testInsertSinglePrimaryColumn()
    {
        $objDB = $this->getConnection();

        if (in_array('agp_temp_upserttest', $this->getConnection()->getTables())) {
            $query = 'DROP TABLE agp_temp_upserttest';
            $this->getConnection()->_pQuery($query);
        }

        $columns = [
            'temp_id' => [DataType::CHAR20, false],
            'temp_int' => [DataType::INT, true],
            'temp_text' => [DataType::TEXT, true],
        ];

        $this->assertTrue($objDB->createTable('agp_temp_upserttest', $columns, ['temp_id']));

        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest')), 0);

        $id1 = $this->generateSystemid();
        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$id1, 1, 'row 1'], ['temp_id']);

        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest', [], null, null, false)), 1);
        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$id1]);
        $this->assertEquals($row['temp_int'], 1); $this->assertEquals($row['temp_text'], 'row 1');

        $objDB->flushQueryCache();

        // first replace
        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$id1, 2, 'row 2'], ['temp_id']);
        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest', [], null, null, false)), 1);
        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$id1]);
        $this->assertEquals($row['temp_int'], 2); $this->assertEquals($row['temp_text'], 'row 2');

        $id2 = $this->generateSystemid();
        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$id2, 3, 'row 3'], ['temp_id']);

        $id3 = $this->generateSystemid();
        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$id3, 4, 'row 4'], ['temp_id']);


        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest', [], null, null, false)), 3);

        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$id3, 5, 'row 5'], ['temp_id']);

        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest', [], null, null, false)), 3);

        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$id1]);
        $this->assertEquals($row['temp_int'], 2); $this->assertEquals($row['temp_text'], 'row 2');

        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$id2]);
        $this->assertEquals($row['temp_int'], 3); $this->assertEquals($row['temp_text'], 'row 3');

        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$id3]);
        $this->assertEquals($row['temp_int'], 5); $this->assertEquals($row['temp_text'], 'row 5');

        $query = 'DROP TABLE agp_temp_upserttest';
        $this->assertTrue($objDB->_pQuery($query));
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testInsertMultiplePrimaryColumn(): void
    {
        $objDB = $this->getConnection();

        if (in_array('agp_temp_upserttest2', $this->getConnection()->getTables(), true)) {
            $query = 'DROP TABLE agp_temp_upserttest2';
            $this->getConnection()->_pQuery($query);
        }

        $columns = [
            'temp_id' => [DataType::CHAR20, false],
            'temp_id2' => [DataType::INT, false],
            'temp_int' => [DataType::INT, true],
            'temp_text' => [DataType::TEXT, true],
        ];

        $this->assertTrue($objDB->createTable('agp_temp_upserttest2', $columns, ['temp_id', 'temp_id2']));

        $this->assertCount(0, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2'));

        $id = $this->generateSystemid();

        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$id, 1, 1, 'row 1'], ['temp_id', 'temp_id2']);

        $this->assertCount(1, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2', [], null, null, false));
        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', [$id, 1]);
        $this->assertEquals(1, $row['temp_int']);
        $this->assertEquals('row 1', $row['temp_text']);

        $objDB->flushQueryCache();

        // first replace
        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$id, 1, 2, 'row 2'], ['temp_id', 'temp_id2']);
        $this->assertCount(1, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2', [], null, null, false));
        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', [$id, 1]);
        $this->assertEquals(2, $row['temp_int']);
        $this->assertEquals('row 2', $row['temp_text']);

        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$id, 2, 3, 'row 3'], ['temp_id', 'temp_id2']);
        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$id, 3, 4, 'row 4'], ['temp_id', 'temp_id2']);

        $this->assertCount(3, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2', [], null, null, false));

        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$id, 3, 5, 'row 5'], ['temp_id', 'temp_id2']);

        $this->assertCount(3, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2', [], null, null, false));

        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', [$id, 1]);
        $this->assertEquals(2, $row['temp_int']);
        $this->assertEquals('row 2', $row['temp_text']);

        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', array($id, 2));
        $this->assertEquals(3, $row['temp_int']);
        $this->assertEquals('row 3', $row['temp_text']);

        $row = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', array($id, 3));
        $this->assertEquals(5, $row['temp_int']);
        $this->assertEquals('row 5', $row['temp_text']);

        $query = 'DROP TABLE agp_temp_upserttest2';
        $this->assertTrue($objDB->_pQuery($query));
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testUpsertPerformance(): void
    {
        $objDB = $this->getConnection();
        if (in_array('agp_temp_upserttest3', $this->getConnection()->getTables(), true)) {
            $query = 'DROP TABLE agp_temp_upserttest3';
            $this->getConnection()->_pQuery($query);
        }

        $columns = [
            'temp_id' => [DataType::CHAR20, false],
            'temp_id2' => [DataType::INT, false],
            'temp_int' => [DataType::INT, true],
            'temp_text' => [DataType::TEXT, true],
        ];

        $this->assertTrue($objDB->createTable('agp_temp_upserttest3', $columns, ['temp_id', 'temp_id2']));

        $id1 = $this->generateSystemid();
        $id2 = $this->generateSystemid();
        $id3 = $this->generateSystemid();

        $testData = [
            [$id1, 1, 1, 'text 1'],
            [$id1, 1, 1, 'text 1'],
            [$id1, 1, 1, 'text 2'],
            [$id1, 2, 1, 'text 1'],
            [$id1, 2, 3, 'text 1'],
            [$id2, 1, 1, 'text 1'],
            [$id2, 1, 1, 'text 1'],
            [$id2, 1, 3, 'text 1'],
            [$id1, 1, 3, 'text 4'],
            [$id1, 1, 3, 'text 5'],
            [$id3, 3, 3, 'text 3'],
            [$id3, 3, 3, 'text 4'],
            [$id3, 4, 3, 'text 4'],
            [$id3, 4, 3, 'text 4'],
            [$id3, 4, 5, 'text 4'],
        ];

        foreach($testData as $row) {
            $this->runInsertAndUpdate($row[0], $row[1], $row[2], $row[3]);
        }

        foreach($testData as $row) {
            $this->runUpsert($row[0], $row[1], $row[2], $row[3]);
        }

        $query = 'DROP TABLE agp_temp_upserttest3';
        $this->assertTrue($objDB->_pQuery($query));
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    private function runUpsert($id, $id2, $int, $text): void
    {
        $this->getConnection()->insertOrUpdate('agp_temp_upserttest3', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$id, $id2, $int, $text], ['temp_id', 'temp_id2']);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    private function runInsertAndUpdate($id, $id2, $int, $text): void
    {
        $objDb = $this->getConnection();
        $row = $objDb->getPRow('SELECT COUNT(*) AS cnt FROM agp_temp_upserttest3 WHERE temp_id = ? AND temp_id2 = ?', array($id, $id2), 0, false);
        if($row['cnt'] == '0') {
            $query = 'INSERT INTO agp_temp_upserttest3 (temp_id, temp_id2, temp_int, temp_text) VALUES (?, ?, ?, ?)';
            $objDb->_pQuery($query, [$id, $id2, $int, $text]);
        } else {
            $query = 'UPDATE agp_temp_upserttest3 SET temp_int = ?, temp_text = ? WHERE temp_id = ? AND temp_id2 = ?';
            $objDb->_pQuery($query, [$int, $text, $id, $id2]);
        }
    }

    // this approach is not feasible! in an update matches a row with the same data, at least mysql returns 0.
    // where not matching: 0 affected, where matching but update not required: 0 affected
    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    private function runInsertAndUpdateChangedRows($id, $id2, $int, $text)
    {
        $objDb = $this->getConnection();

        $query = 'UPDATE agp_temp_upserttest3 SET temp_int = ?, temp_text = ? WHERE temp_id = ? AND temp_id2 = ?';
        $objDb->_pQuery($query, [$int, $text, $id, $id2]);
        if($objDb->getAffectedRowsCount() == 0) {
            $query = 'INSERT INTO agp_temp_upserttest3 (temp_id, temp_id2, temp_int, temp_text) VALUES (?, ?, ?, ?)';
            $objDb->_pQuery($query, [$id, $id2, $int, $text]);
        }
    }
}
