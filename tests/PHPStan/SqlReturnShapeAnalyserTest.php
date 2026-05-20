<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\PHPStan;

use Artemeon\Database\PHPStan\SqlReturnShapeAnalyser;

$analyser = new SqlReturnShapeAnalyser();

$keysOf = static function (string $sql) use ($analyser): array {
    $type = $analyser->analyseRow($sql);
    if ($type === null) {
        return [];
    }
    $arrays = $type->getConstantArrays();
    if (count($arrays) !== 1) {
        return [];
    }

    return array_map(static fn ($k) => $k->getValue(), $arrays[0]->getKeyTypes());
};

it('infers shape from a simple column list', function () use ($keysOf): void {
    expect($keysOf('SELECT id, name FROM users'))->toBe(['id', 'name']);
});

it('uses aliases as keys when present', function () use ($keysOf): void {
    $keys = $keysOf('SELECT name AS user_name, COUNT(*) AS total FROM users GROUP BY name');
    expect($keys)->toContain('user_name')->toContain('total');
});

it('strips backticks and double quotes from identifiers', function () use ($keysOf): void {
    expect($keysOf('SELECT `id`, "name" FROM users'))->toBe(['id', 'name']);
});

it('lets later JOIN columns overwrite earlier ones with the same name', function () use ($keysOf): void {
    expect($keysOf('SELECT a.id, b.id FROM a JOIN b ON a.id = b.id'))->toBe(['id']);
});

it('strips wrapping parentheses around column references', function () use ($keysOf): void {
    expect($keysOf('SELECT DISTINCT(stats_handler) FROM cache'))->toBe(['stats_handler']);
    expect($keysOf('SELECT (`name`) FROM users'))->toBe(['name']);
});

it('falls back to array<string, mixed> for SELECT *', function () use ($analyser): void {
    $type = $analyser->analyseRow('SELECT * FROM users');
    expect($type)->not()->toBeNull();
    expect($type?->getConstantArrays())->toBe([]);
    expect($type?->getIterableKeyType()->describe(\PHPStan\Type\VerbosityLevel::precise()))->toBe('string');
});

it('returns null for non-SELECT statements', function () use ($analyser): void {
    expect($analyser->analyseRow('INSERT INTO users (id) VALUES (1)'))->toBeNull();
    expect($analyser->analyseRow('UPDATE users SET id = 1'))->toBeNull();
    expect($analyser->analyseRow('DELETE FROM users'))->toBeNull();
});

it('returns null for UNION queries', function () use ($analyser): void {
    expect($analyser->analyseRow('SELECT id FROM a UNION SELECT id FROM b'))->toBeNull();
});

it('returns null when SQL fails to parse cleanly', function () use ($analyser): void {
    expect($analyser->analyseRow('not even sql'))->toBeNull();
});
