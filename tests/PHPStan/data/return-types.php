<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\PHPStan\Data;

use Artemeon\Database\ConnectionInterface;

use function PHPStan\Testing\assertType;

function selectList(ConnectionInterface $conn): void
{
    $rows = $conn->getPArray('SELECT id, name FROM users');
    assertType('list<array{id: mixed, name: mixed}>', $rows);
}

function selectRow(ConnectionInterface $conn): void
{
    $row = $conn->getPRow('SELECT id, name FROM users');
    assertType('array{}|array{id: mixed, name: mixed}', $row);
}

function selectGenerator(ConnectionInterface $conn): \Generator
{
    $gen = $conn->getGenerator('SELECT id FROM users');
    assertType('Generator<int, array{id: mixed}>', $gen);

    return $gen;
}

function selectStarFallsBack(ConnectionInterface $conn): void
{
    $rows = $conn->getPArray('SELECT * FROM users');
    assertType('list<array<string, mixed>>', $rows);
}

function dynamicSqlFallsBack(ConnectionInterface $conn, string $sql): void
{
    $rows = $conn->getPArray($sql);
    assertType('array<int, array<string, mixed>>', $rows);
}
