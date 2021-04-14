<?php

namespace Artemeon\Database\Tests\Driver;

use Artemeon\Database\Driver\SqlsrvDriver;
use PHPUnit\Framework\TestCase;

/**
 * @since 7.2
 */
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

        self::assertEquals('SUBSTRING(test_column, 1)', $mssqlDriver->getSubstringExpression('test_column', 1, null));
        self::assertEquals('SUBSTRING(test_column, 1, 1)', $mssqlDriver->getSubstringExpression('test_column', 1, 1));
        self::assertEquals('SUBSTRING("test value", 1)', $mssqlDriver->getSubstringExpression('"test value"', 1, null));
        self::assertEquals('SUBSTRING("test value", 1, 1)', $mssqlDriver->getSubstringExpression('"test value"', 1, 1));
    }
}
