<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\Driver;

use Artemeon\Database\Driver\PostgresDriver;
use PHPUnit\Framework\TestCase;

final class PostgresDriverTest extends TestCase
{
    public function testBuildsDatabaseSpecificSubstringExpression(): void
    {
        $postgresDriver = $this->getMockBuilder(PostgresDriver::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->setMethodsExcept(['getSubstringExpression'])
            ->getMock();

        self::assertEquals('SUBSTRING(cast (test_column as text), 1)', $postgresDriver->getSubstringExpression('test_column', 1, null));
        self::assertEquals('SUBSTRING(cast (test_column as text), 1, 1)', $postgresDriver->getSubstringExpression('test_column', 1, 1));
        self::assertEquals('SUBSTRING(cast ("test value" as text), 1)', $postgresDriver->getSubstringExpression('"test value"', 1, null));
        self::assertEquals('SUBSTRING(cast ("test value" as text), 1, 1)', $postgresDriver->getSubstringExpression('"test value"', 1, 1));
    }
}
