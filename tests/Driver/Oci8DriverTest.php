<?php

namespace Artemeon\Database\Tests\Driver;

use Artemeon\Database\Driver\Oci8Driver;
use PHPUnit\Framework\TestCase;

/**
 * @since 7.2
 */
final class Oci8DriverTest extends TestCase
{
    public function testBuildsDatabaseSpecificSubstringExpression(): void
    {
        $oracleDriver = $this->getMockBuilder(Oci8Driver::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->setMethodsExcept(['getSubstringExpression'])
            ->getMock();

        self::assertEquals('SUBSTR(test_column, 1)', $oracleDriver->getSubstringExpression('test_column', 1, null));
        self::assertEquals('SUBSTR(test_column, 1, 1)', $oracleDriver->getSubstringExpression('test_column', 1, 1));
        self::assertEquals('SUBSTR("test value", 1)', $oracleDriver->getSubstringExpression('"test value"', 1, null));
        self::assertEquals('SUBSTR("test value", 1, 1)', $oracleDriver->getSubstringExpression('"test value"', 1, 1));
    }
}