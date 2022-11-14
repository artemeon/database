<?php

use Artemeon\Database\Connection;
use Artemeon\Database\ConnectionParameters;
use Artemeon\Database\DriverFactory;
use Artemeon\Database\Schema\DataType;

require_once __DIR__ . '/../vendor/autoload.php';

const TEST_TABLE_NAME = 'agp_test_table';

global $connection;

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'test';
$password = getenv('DB_PWD') ?: 'test';
$database = getenv('DB_SCHEMA') ?: ':memory:';
$port = getenv('DB_PORT') ? (int) getenv('DB_PORT') : null;
$driver = getenv('DB_DRIVER') ?: 'sqlite3';

$params = new ConnectionParameters($host, $user, $password, $database, $port, $driver);
$factory = new DriverFactory();

$connection = new Connection($params, $factory);

// insert demo data
$connection->dropTable(TEST_TABLE_NAME);
$connection->createTable(TEST_TABLE_NAME, getTestTableColumns(), ['temp_id']);

$rows = getRows(1000);
foreach ($rows as $row) {
    $connection->insert(TEST_TABLE_NAME, $row);
}

function getTestTableColumns(): array
{
    $columns = array();
    $columns["temp_id"] = array(DataType::STR_TYPE_CHAR20, false);
    $columns["temp_int"] = array(DataType::STR_TYPE_INT, true);
    $columns["temp_bigint"] = array(DataType::STR_TYPE_BIGINT, true);
    $columns["temp_float"] = array(DataType::STR_TYPE_FLOAT, true);
    $columns["temp_char10"] = array(DataType::STR_TYPE_CHAR10, true);
    $columns["temp_char20"] = array(DataType::STR_TYPE_CHAR20, true);
    $columns["temp_char100"] = array(DataType::STR_TYPE_CHAR100, true);
    $columns["temp_char254"] = array(DataType::STR_TYPE_CHAR254, true);
    $columns["temp_char500"] = array(DataType::STR_TYPE_CHAR500, true);
    $columns["temp_text"] = array(DataType::STR_TYPE_TEXT, true);
    $columns["temp_longtext"] = array(DataType::STR_TYPE_LONGTEXT, true);

    return $columns;
}

function generateSystemid(): string
{
    return substr(sha1(uniqid()), 0, 20);
}

function getRows(int $count, bool $assoc = true): array
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $row = [
            'temp_id' => generateSystemid(),
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

function getColumnNames(): array
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


