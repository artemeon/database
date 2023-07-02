<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\Driver;

use Artemeon\Database\Driver\SqlsrvDriver;
use PHPUnit\Framework\TestCase;

final class SqlsrvDriverTest extends TestCase
{
    public function testBuildsDatabaseSpecificSubstringExpression(): void
    {
        $mssqlDriver = $this->getMockBuilder(SqlsrvDriver::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->setMethodsExcept(['getSubstringExpression'])
            ->getMock();

        self::assertEquals('SUBSTRING(test_column, 1, LEN(test_column) - 0)', $mssqlDriver->getSubstringExpression('test_column', 1, null));
        self::assertEquals('SUBSTRING(test_column, 1, 1)', $mssqlDriver->getSubstringExpression('test_column', 1, 1));
        self::assertEquals('SUBSTRING("test value", 1, LEN("test value") - 0)', $mssqlDriver->getSubstringExpression('"test value"', 1, null));
        self::assertEquals('SUBSTRING("test value", 1, 1)', $mssqlDriver->getSubstringExpression('"test value"', 1, 1));
    }
}
