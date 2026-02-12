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

use Artemeon\Database\Connection;
use Artemeon\Database\ConnectionParameters;
use Artemeon\Database\DriverFactory;
use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\Schema\DataType;
use Override;
use PHPUnit\Framework\TestCase;

abstract class ConnectionTestCase extends TestCase
{
    private static ?Connection $connection = null;

    protected const string TEST_TABLE_NAME = 'agp_test_table';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->flushDBCache();
        $this->setupFixture();
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function getConnection(): Connection
    {
        if (self::$connection) {
            return self::$connection;
        }

        $host = getenv('DB_HOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: 'test';
        $password = getenv('DB_PWD') ?: 'test';
        $database = getenv('DB_SCHEMA') ?: ':memory:';
        $port = getenv('DB_PORT') ? (int) getenv('DB_PORT') : null;
        $driver = getenv('DB_DRIVER') ?: 'sqlite3';

        $params = new ConnectionParameters($host, $user, $password, $database, $port, $driver);
        $factory = new DriverFactory();

        self::$connection = new Connection($params, $factory);

        return self::$connection;
    }

    protected function flushDBCache(): void
    {
        $this->getConnection()->flushPreparedStatementsCache();
        $this->getConnection()->flushQueryCache();
        $this->getConnection()->flushTablesCache();
    }

    /**
     * @throws ConnectionException
     * @throws QueryException
     */
    private function setupFixture(): void
    {
        $this->getConnection()->dropTable(self::TEST_TABLE_NAME);
        $this->getConnection()->createTable(self::TEST_TABLE_NAME, $this->getTestTableColumns(), ['temp_id']);

        $rows = $this->getRows(50);
        foreach ($rows as $row) {
            $this->getConnection()->insert(self::TEST_TABLE_NAME, $row);
        }
    }

    /**
     * @return array<non-empty-string, array{DataType, bool}>
     */
    protected function getTestTableColumns(): array
    {
        return [
            'temp_id' => [DataType::CHAR20, false],
            'temp_int' => [DataType::INT, true],
            'temp_bigint' => [DataType::BIGINT, true],
            'temp_float' => [DataType::FLOAT, true],
            'temp_char10' => [DataType::CHAR10, true],
            'temp_char20' => [DataType::CHAR20, true],
            'temp_char100' => [DataType::CHAR100, true],
            'temp_char254' => [DataType::CHAR254, true],
            'temp_char500' => [DataType::CHAR500, true],
            'temp_text' => [DataType::TEXT, true],
            'temp_longtext' => [DataType::LONGTEXT, true],
        ];
    }

    protected function generateSystemid(): string
    {
        return substr(sha1(uniqid()), 0, 20);
    }

    /**
     * @return ($assoc is true ? list<array<non-empty-string, scalar|null>> : list<list<scalar|null>>)
     */
    protected function getRows(int $count, bool $assoc = true): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $row = [
                'temp_id' => $this->generateSystemid(),
                'temp_int' => 123456 + $i,
                'temp_bigint' => 20200508095300 + $i,
                'temp_float' => 23.45,
                'temp_char10' => substr('char10-' . $i, 0, 10),
                'temp_char20' => 'char20-' . $i,
                'temp_char100' => 'char100-' . $i,
                'temp_char254' => 'char254-' . $i,
                'temp_char500' => 'char500-' . $i,
                'temp_text' => 'text-' . $i,
                'temp_longtext' => 'longtext-' . $i,
            ];

            $rows[] = $assoc ? $row : array_values($row);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    protected function getColumnNames(): array
    {
        return [
            'temp_id',
            'temp_int',
            'temp_bigint',
            'temp_float',
            'temp_char10',
            'temp_char20',
            'temp_char100',
            'temp_char254',
            'temp_char500',
            'temp_text',
            'temp_longtext',
        ];
    }
}
