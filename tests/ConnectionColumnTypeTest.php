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
use PHPUnit\Framework\Attributes\DataProvider;

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

    /**
     * @return iterable<string, array{DataType, DataType, bool}>
     */
    public static function provideMapsToSameDatatypeCases(): iterable
    {
        yield 'INT == INT' => [DataType::INT, DataType::INT, true];
        yield 'TEXT == TEXT' => [DataType::TEXT, DataType::TEXT, true];
        yield 'INT vs TEXT must stay distinct on every backend' => [DataType::INT, DataType::TEXT, false];
    }

    #[DataProvider('provideMapsToSameDatatypeCases')]
    public function testMapsToSameDatatype(DataType $a, DataType $b, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->getConnection()->mapsToSameDatatype($a, $b),
        );
    }
}
