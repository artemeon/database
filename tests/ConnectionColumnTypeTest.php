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

class ConnectionColumnTypeTest extends ConnectionTestCase
{
    public function testTypeConversion()
    {
        $connection = $this->getConnection();
        $columns = $this->getTestTableColumns();

        //fetch all columns from the table and match the types
        $columnsFromDb = $connection->getColumnsOfTable(self::TEST_TABLE_NAME);

        foreach ($columnsFromDb as $columnName => $details) {

            //compare both internal types converted to db-based types, those need to match
            $this->assertEquals(
                $this->getConnection()->getDatatype(trim($columns[$columnName][0])),
                $this->getConnection()->getDatatype(trim($details["columnType"]))
            );
        }
    }
}
