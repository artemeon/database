<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\Driver;

use Artemeon\Database\Driver\Sqlite3Driver;
use PHPUnit\Framework\TestCase;

final class Sqlite3DriverTest extends TestCase
{
    public function testBuildsDatabaseSpecificSubstringExpression(): void
    {
        $sqlite3Driver = $this->getMockBuilder(Sqlite3Driver::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->setMethodsExcept(['getSubstringExpression'])
            ->getMock();

        self::assertEquals('SUBSTR(test_column, 1)', $sqlite3Driver->getSubstringExpression('test_column', 1, null));
        self::assertEquals('SUBSTR(test_column, 1, 1)', $sqlite3Driver->getSubstringExpression('test_column', 1, 1));
        self::assertEquals('SUBSTR("test value", 1)', $sqlite3Driver->getSubstringExpression('"test value"', 1, null));
        self::assertEquals('SUBSTR("test value", 1, 1)', $sqlite3Driver->getSubstringExpression('"test value"', 1, 1));
    }
}
