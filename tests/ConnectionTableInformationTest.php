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
use Artemeon\Database\Exception\TableNotFoundException;
use Artemeon\Database\Schema\DataType;
use Artemeon\Database\Schema\TableIndex;
use Artemeon\Database\Schema\TableKey;

class ConnectionTableInformationTest extends ConnectionTestCase
{
    public const TEST_TABLE_NAME = 'agp_temp_tableinfotest';

    /**
     * @throws TableNotFoundException
     * @throws ConnectionException
     * @throws QueryException
     */
    public function testTypeConversion(): void
    {
        $db = $this->getConnection();

        if (in_array(self::TEST_TABLE_NAME, $this->getConnection()->getTables(), true)) {
            $strQuery = 'DROP TABLE ' . self::TEST_TABLE_NAME;
            $this->getConnection()->_pQuery($strQuery);
        }

        $colDefinitions = [
            'temp_int' => [DataType::INT, false],
            'temp_long' => [DataType::BIGINT, true],
            'temp_double' => [DataType::FLOAT, true],
            'temp_char10' => [DataType::CHAR10, true],
            'temp_char20' => [DataType::CHAR20, true],
            'temp_char100' => [DataType::CHAR100, true],
            'temp_char254' => [DataType::CHAR254, true],
            'temp_char500' => [DataType::CHAR500, true],
            'temp_text' => [DataType::TEXT, true],
            'temp_longtext' => [DataType::LONGTEXT, true],
        ];

        $this->assertTrue($db->createTable(self::TEST_TABLE_NAME, $colDefinitions, ['temp_int']));
        $this->assertTrue($db->createIndex(self::TEST_TABLE_NAME, 'temp_double', ['temp_double']));
        $this->assertTrue($db->createIndex(self::TEST_TABLE_NAME, 'temp_char500', ['temp_char500']));
        $this->assertTrue($db->createIndex(self::TEST_TABLE_NAME, 'temp_combined', ['temp_double', 'temp_char500']));

        // load the schema info from the db
        $info = $db->getTableInformation(self::TEST_TABLE_NAME);

        $keyNames = array_map(static fn (TableKey $key) => $key->getName(), $info->getPrimaryKeys());

        $this->assertContains('temp_int', $keyNames);

        $indexNames = array_map(static fn (TableIndex $index) => $index->getName(), $info->getIndexes());

        $this->assertContains('temp_double', $indexNames);
        $this->assertContains('temp_char500', $indexNames);
        $this->assertContains('temp_combined', $indexNames);
    }
}
