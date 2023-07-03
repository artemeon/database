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

class ConnectionMultiInsertTest extends ConnectionTestCase
{
    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->getConnection()->_pQuery('DELETE FROM ' . self::TEST_TABLE_NAME . ' WHERE 1=1');
        $this->getConnection()->flushQueryCache();
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testInserts(): void
    {
        $values = $this->getRows(50, false);
        $connection = $this->getConnection();

        $return = $connection->multiInsert(self::TEST_TABLE_NAME, $this->getColumnNames(), $values);
        $this->assertTrue($return);

        $row = $connection->getPRow('SELECT COUNT(*) AS cnt FROM ' . self::TEST_TABLE_NAME);
        $this->assertEquals(50, $row['cnt']);

        for ($i = 1; $i <= 50; $i++) {
            $row = $connection->getPRow('SELECT * FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_int = ?', [123456 + $i]);

            $this->assertEquals(123456 + $i, $row['temp_int']);
            $this->assertEquals(20200508095300 + $i, $row['temp_bigint']);
            $this->assertEquals(23.45, round((float) $row['temp_float'], 2));
            $this->assertEquals('char10-' . $i, $row['temp_char10']);
            $this->assertEquals('char20-' . $i, $row['temp_char20']);
            $this->assertEquals('char100-' . $i, $row['temp_char100']);
            $this->assertEquals('char254-' . $i, $row['temp_char254']);
            $this->assertEquals('char500-' . $i, $row['temp_char500']);
            $this->assertEquals('text-' . $i, $row['temp_text']);
            $this->assertEquals('longtext-' . $i, $row['temp_longtext']);
        }
    }

    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testInsertsLimit(): void
    {
        $values = $this->getRows(1000, false);
        $connection = $this->getConnection();

        $return = $connection->multiInsert(self::TEST_TABLE_NAME, $this->getColumnNames(), $values);
        $this->assertTrue($return);

        $row = $connection->getPRow('SELECT COUNT(*) AS cnt FROM ' . self::TEST_TABLE_NAME);
        $this->assertEquals(1000, $row['cnt']);
    }
}
