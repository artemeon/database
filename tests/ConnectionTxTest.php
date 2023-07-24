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
use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Schema\DataType;

class ConnectionTxTest extends ConnectionTestCase
{
    /**
     * @throws ConnectionException
     * @throws CommitException
     * @throws QueryException
     */
    public function test(): void
    {
        $connection = $this->getConnection();

        $columns = [
            'temp_id' => [DataType::CHAR20, false],
            'temp_long' => [DataType::BIGINT, true],
            'temp_double' => [DataType::FLOAT, true],
            'temp_char10' => [DataType::CHAR10, true],
            'temp_char20' => [DataType::CHAR20, true],
            'temp_char100' => [DataType::CHAR100, true],
            'temp_char254' => [DataType::CHAR254, true],
            'temp_char500' => [DataType::CHAR500, true],
            'temp_text' => [DataType::TEXT, true],
        ];

        $this->assertTrue($connection->createTable('agp_temp_autotest_tx', $columns, ['temp_id']), 'testTx createTable');

        $connection->_pQuery('DELETE FROM agp_temp_autotest_tx WHERE 1=1');

        $i = 1;
        $query = "INSERT INTO agp_temp_autotest_tx
            (temp_id, temp_long, temp_double, temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text)
            VALUES
            ('" . $this->generateSystemid() . "', 123456" . $i . ', 23.45' . $i . ", '" . $i . "', 'char20" . $i . "', 'char100" . $i . "', 'char254" . $i . "', 'char500" . $i . "', 'text" . $i . "')";

        $this->assertTrue($connection->_pQuery($query), 'testTx insert');

        $query = 'SELECT * FROM agp_temp_autotest_tx ORDER BY temp_long ASC';
        $rows = $connection->getPArray($query);
        $this->assertCount(1, $rows, 'testDataBase getRow count');
        $this->assertEquals('1', $rows[0]['temp_char10'], 'testTx getRow content');

        $connection->flushQueryCache();
        $connection->beginTransaction();

        $i = 2;
        $query = "INSERT INTO agp_temp_autotest_tx
            (temp_id, temp_long, temp_double, temp_char10, temp_char20, temp_char100, temp_char254, temp_char500, temp_text)
            VALUES
            ('" . $this->generateSystemid() . "', 123456" . $i . ', 23.45' . $i . ", '" . $i . "', 'char20" . $i . "', 'char100" . $i . "', 'char254" . $i . "', 'char500" . $i . "', 'text" . $i . "')";

        $this->assertTrue($connection->_pQuery($query), 'testTx insert');

        $connection->rollBack();
        $count = $connection->getPRow('SELECT COUNT(*) AS cnt FROM agp_temp_autotest_tx');
        $this->assertEquals(1, $count['cnt'], 'testTx rollback');

        $connection->flushQueryCache();

        $connection->beginTransaction();
        $this->assertTrue($connection->_pQuery($query), 'testTx insert');
        $connection->commit();

        $count = $connection->getPRow('SELECT COUNT(*) AS cnt FROM agp_temp_autotest_tx');
        $this->assertEquals(2, $count['cnt'], 'testTx rollback');

        $connection->flushQueryCache();

        $query = 'DROP TABLE agp_temp_autotest_tx';
        $this->assertTrue($connection->_pQuery($query), 'testTx dropTable');
    }

    /**
     * @throws ConnectionException
     */
    public function testRollbackOnCommit(): void
    {
        $this->expectException(CommitException::class);

        $connection = $this->getConnection();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->rollBack();
        $connection->commit();
    }
}

