<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\Driver;

use Artemeon\Database\ConnectionParameters;
use Artemeon\Database\Driver\PostgresDriver;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class PostgresDriverTest extends TestCase
{
    public function testBuildsDatabaseSpecificSubstringExpression(): void
    {
        $postgresDriver = new PostgresDriver();

        self::assertEquals('SUBSTRING(cast (test_column as text), 1)', $postgresDriver->getSubstringExpression('test_column', 1, null));
        self::assertEquals('SUBSTRING(cast (test_column as text), 1, 1)', $postgresDriver->getSubstringExpression('test_column', 1, 1));
        self::assertEquals('SUBSTRING(cast ("test value" as text), 1)', $postgresDriver->getSubstringExpression('"test value"', 1, null));
        self::assertEquals('SUBSTRING(cast ("test value" as text), 1, 1)', $postgresDriver->getSubstringExpression('"test value"', 1, 1));
    }

    public static function provideValidExportFilenameAndPasswordAndExpectedCommandLine()
    {
        return [
            [
                '/path/to/dump.sql', ['agp_user', 'agp_tours'],
                'securepassword',
                "'bash' '-c' '/usr/bin/pg_dump --clean --no-owner -h '\''localhost'\'' -U '\''sebastian_bergmann'\'' -p 5432 -d '\''testdb'\'' -t '\''agp_user'\'' -t '\''agp_tours'\'' | gzip > '\''/path/to/dump.sql.gz'\'''",
            ],
            [
                '/path/to/dump.sql', [],
                'securepassword',
                "'bash' '-c' '/usr/bin/pg_dump --clean --no-owner -h '\''localhost'\'' -U '\''sebastian_bergmann'\'' -p 5432 -d '\''testdb'\''  | gzip > '\''/path/to/dump.sql.gz'\'''",
            ],
        ];
    }

    #[DataProvider('provideValidExportFilenameAndPasswordAndExpectedCommandLine')]
    public function testDbExportWillRunProcess(string $fileName, array $tables, string $password, string $expectedCommandLine): void
    {
        $host = 'localhost';
        $user = 'sebastian_bergmann';
        $database = 'testdb';
        $port = 5432;
        $driver = 'sqlite3';

        $configMock = new ConnectionParameters($host, $user, $password, $database, $port, $driver);

        $dbServiceMock = Mockery::mock(PostgresDriver::class)
            ->makePartial();

        $dbServiceMock->shouldReceive('handlesDumpCompression')
            ->once()
            ->andReturn(true);

        $dbServiceMock->shouldAllowMockingProtectedMethods()
            ->shouldReceive('runProcess')
            ->once()
            ->withArgs([
                static function (Process $process) use ($expectedCommandLine): bool {
                    self::assertSame($expectedCommandLine, $process->getCommandLine());

                    return true;
                },
            ])
            ->andReturn(true);

        $dbServiceMock->setConfig($configMock);

        $result = $dbServiceMock->dbExport($fileName, $tables);

        self::assertTrue($result);
    }

    public static function provideValidImportFilenameAndPasswordAndExpectedCommandLine()
    {
        return [
            [
                '/path/to/dump.sql', 'securepassword',
                "'/usr/bin/psql' '-q' '-h' 'localhost' '-U' 'sebastian_bergmann' '-p5432' '-d' 'testdb' '-f' '/path/to/dump.sql'",
            ],
            [
                '/path/to/dump.sql.gz', 'securepassword',
                "'bash' '-c' 'gunzip -c '\''/path/to/dump.sql.gz'\'' | /usr/bin/psql -q -h '\''localhost'\'' -U '\''sebastian_bergmann'\'' -p5432 -d '\''testdb'\'''",
            ],
            [
                '/path/to/dump.sql', '',
                "'/usr/bin/psql' '-q' '-h' 'localhost' '-U' 'sebastian_bergmann' '-p5432' '-d' 'testdb' '-f' '/path/to/dump.sql'",
            ],
            [
                '/path/to/dump.sql.gz', '',
                "'bash' '-c' 'gunzip -c '\''/path/to/dump.sql.gz'\'' | /usr/bin/psql -q -h '\''localhost'\'' -U '\''sebastian_bergmann'\'' -p5432 -d '\''testdb'\'''",
            ],
        ];
    }

    #[DataProvider('provideValidImportFilenameAndPasswordAndExpectedCommandLine')]
    public function testDbImportWillRunProcess(string $fileName, string $password, string $expectedCommandLine): void
    {
        $host = 'localhost';
        $user = 'sebastian_bergmann';
        $database = 'testdb';
        $port = 5432;
        $driver = 'mysqli';

        $configMock = new ConnectionParameters($host, $user, $password, $database, $port, $driver);

        $dbServiceMock = Mockery::mock(PostgresDriver::class)
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        $dbServiceMock->shouldReceive('handlesDumpCompression')
            ->once()
            ->andReturn(true);

        $dbServiceMock->shouldReceive('runProcess')
            ->once()
            ->withArgs(function (Process $process) use ($expectedCommandLine): bool {
                self::assertSame($expectedCommandLine, $process->getCommandLine());

                return true;
            })
            ->andReturn(true);

        $dbServiceMock->setConfig($configMock);

        $result = $dbServiceMock->dbImport($fileName);

        self::assertTrue($result);
    }

    public function testDbImportWillThrowException(): void
    {
        $fileName = '/path/to/dump.wrong';

        $this->expectException(\RuntimeException::class); // Erwartet, dass eine RuntimeException geworfen wird
        $this->expectExceptionMessage($fileName . ' is not a valid import file'); // Erwartet die spezifische Fehlermeldung

        $dbServiceMock = $this->getMockBuilder(PostgresDriver::class)
            ->onlyMethods(['runProcess'])
            ->getMock();
        $dbServiceMock->expects($this->never())->method('runProcess');
        $result = $dbServiceMock->dbImport($fileName);

        self::assertTrue($result);
    }
}
