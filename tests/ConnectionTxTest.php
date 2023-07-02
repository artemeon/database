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

use Artemeon\Database\Exception\CommitException;
use Artemeon\Database\Schema\DataType;

class ConnectionTxTest extends ConnectionTestCase
{
    public function test(): void
    {
        $connection = $this->getConnection();

        $arrFields = [];
        $arrFields['temp_id'] = [DataType::CHAR20, false];
        $arrFields['temp_long'] = [DataType::BIGINT, true];
        $arrFields['temp_double'] = [DataType::FLOAT, true];
        $arrFields['temp_char10'] = [DataType::CHAR10, true];
        $arrFields['temp_char20'] = [DataType::CHAR20, true];
        $arrFields['temp_char100'] = [DataType::CHAR100, true];
        $arrFields['temp_char254'] = [DataType::CHAR254, true];
        $arrFields['temp_char500'] = [DataType::CHAR500, true];
        $arrFields['temp_text'] = [DataType::TEXT, true];

        $this->assertTrue($connection->createTable('agp_temp_autotest_tx', $arrFields, ['temp_id']), 'testTx createTable');

        $connection->_pQuery('DELETE FROM agp_temp_autotest_tx WHERE 1=1');

        $intI = 1;
        $strQuery = "INSERT INTO agp_temp_autotest_tx
            (temp_id, temp_long, temp_double, temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text)
            VALUES
            ('" . $this->generateSystemid() . "', 123456" . $intI . ', 23.45' . $intI . ", '" . $intI . "', 'char20" . $intI . "', 'char100" . $intI . "', 'char254" . $intI . "', 'char500" . $intI . "', 'text" . $intI . "')";

        $this->assertTrue($connection->_pQuery($strQuery), 'testTx insert');

        $strQuery = 'SELECT * FROM agp_temp_autotest_tx ORDER BY temp_long ASC';
        $arrRow = $connection->getPArray($strQuery);
        $this->assertEquals(1, count($arrRow), 'testDataBase getRow count');
        $this->assertEquals('1', $arrRow[0]['temp_char10'], 'testTx getRow content');

        $connection->flushQueryCache();
        $connection->beginTransaction();

        $intI = 2;
        $strQuery = "INSERT INTO agp_temp_autotest_tx
            (temp_id, temp_long, temp_double, temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text)
            VALUES
            ('" . $this->generateSystemid() . "', 123456" . $intI . ', 23.45' . $intI . ", '" . $intI . "', 'char20" . $intI . "', 'char100" . $intI . "', 'char254" . $intI . "', 'char500" . $intI . "', 'text" . $intI . "')";

        $this->assertTrue($connection->_pQuery($strQuery), 'testTx insert');

        $connection->rollbackTransaction();
        $arrCount = $connection->getPRow('SELECT COUNT(*) AS cnt FROM agp_temp_autotest_tx');
        $this->assertEquals(1, $arrCount['cnt'], 'testTx rollback');

        $connection->flushQueryCache();

        $connection->beginTransaction();
        $this->assertTrue($connection->_pQuery($strQuery), 'testTx insert');
        $connection->commitTransaction();

        $arrCount = $connection->getPRow('SELECT COUNT(*) AS cnt FROM agp_temp_autotest_tx');
        $this->assertEquals(2, $arrCount['cnt'], 'testTx rollback');

        $connection->flushQueryCache();

        $strQuery = 'DROP TABLE agp_temp_autotest_tx';
        $this->assertTrue($connection->_pQuery($strQuery), 'testTx dropTable');
    }

    public function testRollbackOnCommit()
    {
        $this->expectException(CommitException::class);

        $connection = $this->getConnection();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->rollbackTransaction();
        $connection->commitTransaction();
    }
}

