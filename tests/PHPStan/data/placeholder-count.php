<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\PHPStan\Data;

use Artemeon\Database\ConnectionInterface;

function placeholderCountSamples(ConnectionInterface $conn): void
{
    $conn->getPArray('SELECT id FROM users WHERE a = ? AND b = ?', [1, 2]);
    $conn->getPArray('SELECT id FROM users WHERE a = ? AND b = ?', [1]);
    $conn->getPRow('SELECT id FROM users WHERE a = ?', []);
    $conn->getPArray("SELECT id FROM users WHERE name = 'a?b' AND id = ?", [1]);
}
