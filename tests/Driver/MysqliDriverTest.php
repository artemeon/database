<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\Driver;

use Artemeon\Database\ConnectionParameters;
use Artemeon\Database\Driver\MysqliDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @internal
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

    public static function provideValidExportFilenameAndPasswordAndExpectedCommandLine()
    {
        return [
            [
                '/path/to/dump.sql', [],
                'securepassword',
                "'bash' '-c' '/usr/bin/mysqldump -h '\''localhost'\'' -u '\''sebastian_bergmann'\'' -p'\''securepassword'\'' -P 3306 '\''testdb'\''  | gzip > '\''/path/to/dump.sql.gz'\'''",
            ],
            [
                '/path/to/dump.sql', ['agp_user', 'agp_tours'],
                'securepassword',
                "'bash' '-c' '/usr/bin/mysqldump -h '\''localhost'\'' -u '\''sebastian_bergmann'\'' -p'\''securepassword'\'' -P 3306 '\''testdb'\'' '\''agp_user'\'' '\''agp_tours'\'' | gzip > '\''/path/to/dump.sql.gz'\'''",
            ],
        ];
    }

    /**
     * @dataProvider provideValidExportFilenameAndPasswordAndExpectedCommandLine
     */
    public function testDbExportWillRunProcess(string $fileName, array $tables, string $password, string $expectedCommandLine): void
    {
        $host = 'localhost';
        $user = 'sebastian_bergmann';
        $database = 'testdb';
        $port = 3306;
        $driver = 'mysqli';

        $configMock = new ConnectionParameters($host, $user, $password, $database, $port, $driver);

        $dbServiceMock = $this->getMockBuilder(MysqliDriver::class)
            ->onlyMethods(['handlesDumpCompression', 'runProcess'])
            ->getMock();
        $dbServiceMock->expects($this->once())->method('handlesDumpCompression')->willReturn(true);

        $dbServiceMock->expects($this->once())
            ->method('runProcess')
            ->with($this->callback(function (Process $process) use ($expectedCommandLine) {
                $this->assertSame($expectedCommandLine, $process->getCommandLine());

                return true;
            }))->willReturn(true);

        $dbServiceMock->setConfig($configMock);

        $result = $dbServiceMock->dbExport($fileName, $tables);

        $this->assertTrue($result);
    }

    public static function provideValidImportFilenameAndPasswordAndExpectedCommandLine()
    {
        return [
            [
                '/path/to/dump.sql', 'securepassword',
                "'bash' '-c' 'cat '\''/path/to/dump.sql'\'' | /usr/bin/mysql -h '\''localhost'\'' -u '\''sebastian_bergmann'\'' -p'\''securepassword'\'' -P 3306 '\''testdb'\'''",
            ],
            [
                '/path/to/dump.sql.gz', 'securepassword',
                "'bash' '-c' 'gunzip -c '\''/path/to/dump.sql.gz'\'' | /usr/bin/mysql -h '\''localhost'\'' -u '\''sebastian_bergmann'\'' -p'\''securepassword'\'' -P 3306 '\''testdb'\'''",
            ],
            [
                '/path/to/dump.sql', '',
                "'bash' '-c' 'cat '\''/path/to/dump.sql'\'' | /usr/bin/mysql -h '\''localhost'\'' -u '\''sebastian_bergmann'\''  -P 3306 '\''testdb'\'''",
            ],
            [
                '/path/to/dump.sql.gz', '',
                "'bash' '-c' 'gunzip -c '\''/path/to/dump.sql.gz'\'' | /usr/bin/mysql -h '\''localhost'\'' -u '\''sebastian_bergmann'\''  -P 3306 '\''testdb'\'''",
            ],
        ];
    }

    /**
     * @dataProvider provideValidImportFilenameAndPasswordAndExpectedCommandLine
     */
    public function testDbImportWillRunProcess(string $fileName, string $password, string $expectedCommandLine): void
    {
        $host = 'localhost';
        $user = 'sebastian_bergmann';
        $database = 'testdb';
        $port = 3306;
        $driver = 'mysqli';

        $configMock = new ConnectionParameters($host, $user, $password, $database, $port, $driver);

        $dbServiceMock = $this->getMockBuilder(MysqliDriver::class)
            ->onlyMethods(['handlesDumpCompression', 'runProcess'])
            ->getMock();
        $dbServiceMock->expects($this->once())->method('handlesDumpCompression')->willReturn(true);

        $dbServiceMock->expects($this->once())
            ->method('runProcess')
            ->with($this->callback(function (Process $process) use ($expectedCommandLine) {
                $this->assertSame($expectedCommandLine, $process->getCommandLine());

                return true;
            }))->willReturn(true);

        $dbServiceMock->setConfig($configMock);

        $result = $dbServiceMock->dbImport($fileName);

        $this->assertTrue($result);
    }

    public function testDbImportWillThrowException(): void
    {
        $fileName = '/path/to/dump.wrong';

        $this->expectException(\RuntimeException::class); // Erwartet, dass eine RuntimeException geworfen wird
        $this->expectExceptionMessage($fileName . ' is not a valid import file'); // Erwartet die spezifische Fehlermeldung

        $dbServiceMock = $this->getMockBuilder(MysqliDriver::class)
            ->onlyMethods(['runProcess'])
            ->getMock();
        $dbServiceMock->expects($this->never())->method('runProcess');
        $result = $dbServiceMock->dbImport($fileName);

        $this->assertTrue($result);
    }
}
