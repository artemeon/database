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

/**
 * @internal
 */
class ConnectionColumnTypeTest extends ConnectionTestCase
{
    /**
     * @throws QueryException
     * @throws ConnectionException
     * @throws TableNotFoundException
     */
    public function testTypeConversion(): void
    {
        $connection = $this->getConnection();
        $columns = $this->getTestTableColumns();

        // fetch all columns from the table and match the types
        $columnsFromDb = $connection->getColumnsOfTable(self::TEST_TABLE_NAME);

        foreach ($columnsFromDb as $columnName => $details) {
            // compare both internal types converted to db-based types, those need to match.
            $this->assertEquals(
                $this->getConnection()->getDatatype($columns[$columnName][0]),
                $this->getConnection()->getDatatype($details['columnType']),
            );
        }
    }

    public function testMapsToSameDatatype(): void
    {
        $connection = $this->getConnection();

        $this->assertTrue($connection->mapsToSameDatatype(DataType::INT, DataType::INT));
        $this->assertTrue($connection->mapsToSameDatatype(DataType::TEXT, DataType::TEXT));

        // distinct types must not collapse on any backend
        $this->assertFalse($connection->mapsToSameDatatype(DataType::INT, DataType::TEXT));
    }
}
