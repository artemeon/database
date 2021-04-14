<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\Driver;

use Artemeon\Database\Driver\MysqliDriver;
use PHPUnit\Framework\TestCase;

/**
 * @since 7.2
 */
final class MysqliDriverTest extends TestCase
{
    public function testBuildsDatabaseSpecificSubstringExpression(): void
    {
        $mysqliDriver = $this->getMockBuilder(MysqliDriver::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->setMethodsExcept(['getSubstringExpression'])
            ->getMock();

        self::assertEquals('SUBSTRING(test_column, 1)', $mysqliDriver->getSubstringExpression('test_column', 1, null));
        self::assertEquals('SUBSTRING(test_column, 1, 1)', $mysqliDriver->getSubstringExpression('test_column', 1, 1));
        self::assertEquals('SUBSTRING("test value", 1)', $mysqliDriver->getSubstringExpression('"test value"', 1, null));
        self::assertEquals('SUBSTRING("test value", 1, 1)', $mysqliDriver->getSubstringExpression('"test value"', 1, 1));
    }
}
