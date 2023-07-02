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
            $strQuery = 'DROP TABLE agp_temp_upserttest';
            $this->getConnection()->_pQuery($strQuery);
        }

        $arrFields = [];
        $arrFields['temp_id'] = [DataType::CHAR20, false];
        $arrFields['temp_int'] = [DataType::INT, true];
        $arrFields['temp_text'] = [DataType::TEXT, true];

        $this->assertTrue($objDB->createTable('agp_temp_upserttest', $arrFields, ['temp_id']));

        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest')), 0);

        $strId1 = $this->generateSystemid();
        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$strId1, 1, 'row 1'], ['temp_id']);

        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest', [], null, null, false)), 1);
        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$strId1]);
        $this->assertEquals($arrRow['temp_int'], 1); $this->assertEquals($arrRow['temp_text'], 'row 1');

        $objDB->flushQueryCache();

        // first replace
        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$strId1, 2, 'row 2'], ['temp_id']);
        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest', [], null, null, false)), 1);
        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$strId1]);
        $this->assertEquals($arrRow['temp_int'], 2); $this->assertEquals($arrRow['temp_text'], 'row 2');

        $strId2 = $this->generateSystemid();
        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$strId2, 3, 'row 3'], ['temp_id']);

        $strId3 = $this->generateSystemid();
        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$strId3, 4, 'row 4'], ['temp_id']);


        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest', [], null, null, false)), 3);

        $objDB->insertOrUpdate('agp_temp_upserttest', ['temp_id', 'temp_int', 'temp_text'], [$strId3, 5, 'row 5'], ['temp_id']);

        $this->assertEquals(count($objDB->getPArray('SELECT * FROM agp_temp_upserttest', [], null, null, false)), 3);

        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$strId1]);
        $this->assertEquals($arrRow['temp_int'], 2); $this->assertEquals($arrRow['temp_text'], 'row 2');

        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$strId2]);
        $this->assertEquals($arrRow['temp_int'], 3); $this->assertEquals($arrRow['temp_text'], 'row 3');

        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest WHERE temp_id = ?', [$strId3]);
        $this->assertEquals($arrRow['temp_int'], 5); $this->assertEquals($arrRow['temp_text'], 'row 5');

        $strQuery = 'DROP TABLE agp_temp_upserttest';
        $this->assertTrue($objDB->_pQuery($strQuery));
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testInsertMultiplePrimaryColumn(): void
    {
        $objDB = $this->getConnection();

        if (in_array('agp_temp_upserttest2', $this->getConnection()->getTables(), true)) {
            $strQuery = 'DROP TABLE agp_temp_upserttest2';
            $this->getConnection()->_pQuery($strQuery);
        }

        $arrFields = [];
        $arrFields['temp_id'] = [DataType::CHAR20, false];
        $arrFields['temp_id2'] = [DataType::INT, false];
        $arrFields['temp_int'] = [DataType::INT, true];
        $arrFields['temp_text'] = [DataType::TEXT, true];

        $this->assertTrue($objDB->createTable('agp_temp_upserttest2', $arrFields, ['temp_id', 'temp_id2']));

        $this->assertCount(0, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2'));

        $strId = $this->generateSystemid();

        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$strId, 1, 1, 'row 1'], ['temp_id', 'temp_id2']);

        $this->assertCount(1, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2', [], null, null, false));
        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', [$strId, 1]);
        $this->assertEquals(1, $arrRow['temp_int']);
        $this->assertEquals('row 1', $arrRow['temp_text']);

        $objDB->flushQueryCache();

        // first replace
        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$strId, 1, 2, 'row 2'], ['temp_id', 'temp_id2']);
        $this->assertCount(1, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2', [], null, null, false));
        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', [$strId, 1]);
        $this->assertEquals(2, $arrRow['temp_int']);
        $this->assertEquals('row 2', $arrRow['temp_text']);

        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$strId, 2, 3, 'row 3'], ['temp_id', 'temp_id2']);
        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$strId, 3, 4, 'row 4'], ['temp_id', 'temp_id2']);

        $this->assertCount(3, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2', [], null, null, false));

        $objDB->insertOrUpdate('agp_temp_upserttest2', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$strId, 3, 5, 'row 5'], ['temp_id', 'temp_id2']);

        $this->assertCount(3, $objDB->getPArray('SELECT * FROM agp_temp_upserttest2', [], null, null, false));

        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', [$strId, 1]);
        $this->assertEquals(2, $arrRow['temp_int']);
        $this->assertEquals('row 2', $arrRow['temp_text']);

        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', array($strId, 2));
        $this->assertEquals(3, $arrRow['temp_int']);
        $this->assertEquals('row 3', $arrRow['temp_text']);

        $arrRow = $objDB->getPRow('SELECT * FROM agp_temp_upserttest2 WHERE temp_id = ? AND temp_id2 = ?', array($strId, 3));
        $this->assertEquals(5, $arrRow['temp_int']);
        $this->assertEquals('row 5', $arrRow['temp_text']);

        $strQuery = 'DROP TABLE agp_temp_upserttest2';
        $this->assertTrue($objDB->_pQuery($strQuery));
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testUpsertPerformance(): void
    {
        $objDB = $this->getConnection();
        if (in_array('agp_temp_upserttest3', $this->getConnection()->getTables(), true)) {
            $strQuery = 'DROP TABLE agp_temp_upserttest3';
            $this->getConnection()->_pQuery($strQuery);
        }

        $arrFields = [
            'temp_id' => [DataType::CHAR20, false],
            'temp_id2' => [DataType::INT, false],
            'temp_int' => [DataType::INT, true],
            'temp_text' => [DataType::TEXT, true],
        ];

        $this->assertTrue($objDB->createTable('agp_temp_upserttest3', $arrFields, ['temp_id', 'temp_id2']));

        $strId1 = $this->generateSystemid();
        $strId2 = $this->generateSystemid();
        $strId3 = $this->generateSystemid();

        $arrTestData = [
            [$strId1, 1, 1, 'text 1'],
            [$strId1, 1, 1, 'text 1'],
            [$strId1, 1, 1, 'text 2'],
            [$strId1, 2, 1, 'text 1'],
            [$strId1, 2, 3, 'text 1'],
            [$strId2, 1, 1, 'text 1'],
            [$strId2, 1, 1, 'text 1'],
            [$strId2, 1, 3, 'text 1'],
            [$strId1, 1, 3, 'text 4'],
            [$strId1, 1, 3, 'text 5'],
            [$strId3, 3, 3, 'text 3'],
            [$strId3, 3, 3, 'text 4'],
            [$strId3, 4, 3, 'text 4'],
            [$strId3, 4, 3, 'text 4'],
            [$strId3, 4, 5, 'text 4'],
        ];

        foreach($arrTestData as $arrOneRow) {
            $this->runInsertAndUpdate($arrOneRow[0], $arrOneRow[1], $arrOneRow[2], $arrOneRow[3]);
        }

        foreach($arrTestData as $arrOneRow) {
            $this->runUpsert($arrOneRow[0], $arrOneRow[1], $arrOneRow[2], $arrOneRow[3]);
        }

        $strQuery = 'DROP TABLE agp_temp_upserttest3';
        $this->assertTrue($objDB->_pQuery($strQuery));
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    private function runUpsert($intId, $intId2, $intInt, $strText): void
    {
        $this->getConnection()->insertOrUpdate('agp_temp_upserttest3', ['temp_id', 'temp_id2', 'temp_int', 'temp_text'], [$intId, $intId2, $intInt, $strText], ['temp_id', 'temp_id2']);
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    private function runInsertAndUpdate($intId, $intId2, $intInt, $strText): void
    {
        $objDb = $this->getConnection();
        $arrRow = $objDb->getPRow('SELECT COUNT(*) AS cnt FROM agp_temp_upserttest3 WHERE temp_id = ? AND temp_id2 = ?', array($intId, $intId2), 0, false);
        if($arrRow['cnt'] == '0') {
            $strQuery = 'INSERT INTO agp_temp_upserttest3 (temp_id, temp_id2, temp_int, temp_text) VALUES (?, ?, ?, ?)';
            $objDb->_pQuery($strQuery, [$intId, $intId2, $intInt, $strText]);
        } else {
            $strQuery = 'UPDATE agp_temp_upserttest3 SET temp_int = ?, temp_text = ? WHERE temp_id = ? AND temp_id2 = ?';
            $objDb->_pQuery($strQuery, [$intInt, $strText, $intId, $intId2]);
        }
    }

    // this approach is not feasible! in an update matches a row with the same data, at least mysql returns 0.
    // where not matching: 0 affected, where matching but update not required: 0 affected
    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    private function runInsertAndUpdateChangedRows($intId, $intId2, $intInt, $strText)
    {
        $objDb = $this->getConnection();

        $strQuery = 'UPDATE agp_temp_upserttest3 SET temp_int = ?, temp_text = ? WHERE temp_id = ? AND temp_id2 = ?';
        $objDb->_pQuery($strQuery, [$intInt, $strText, $intId, $intId2]);
        if($objDb->getAffectedRowsCount() == 0) {
            $strQuery = 'INSERT INTO agp_temp_upserttest3 (temp_id, temp_id2, temp_int, temp_text) VALUES (?, ?, ?, ?)';
            $objDb->_pQuery($strQuery, [$intId, $intId2, $intInt, $strText]);
        }
    }
}
