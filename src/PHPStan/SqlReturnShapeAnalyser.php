<?php

declare(strict_types=1);

namespace Artemeon\Database\PHPStan;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

/**
 * Parses a SQL query string and derives a constant array shape describing a single row.
 *
 * Schema-agnostic: only the shape (keys) is inferred; all values are typed as mixed.
 * Returns null when the query cannot be safely shaped (non-SELECT, SELECT *, UNION/CTE, parse failure).
 */
final class SqlReturnShapeAnalyser
{
    /**
     * @var array<string, Type|null>
     */
    private array $cache = [];

    public function analyseRow(string $query): ?Type
    {
        $key = md5($query);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->doAnalyseRow($query);
    }

    private function doAnalyseRow(string $query): ?Type
    {
        try {
            $parser = new Parser($query);
        } catch (\Throwable) {
            return null;
        }

        if (count($parser->statements) !== 1) {
            return null;
        }

        $statement = $parser->statements[0];
        if (!$statement instanceof SelectStatement) {
            return null;
        }

        if ($statement->union !== []) {
            return null;
        }

        if ($statement->expr === null) {
            return null;
        }

        $builder = ConstantArrayTypeBuilder::createEmpty();
        foreach ($statement->expr as $expr) {
            if (!$expr instanceof Expression) {
                return null;
            }

            $key = $this->keyFor($expr);
            if ($key === null) {
                return $this->fallbackRow();
            }

            $builder->setOffsetValueType(
                new ConstantStringType($key),
                new MixedType(),
            );
        }

        return $builder->getArray();
    }

    private function keyFor(Expression $expr): ?string
    {
        if ($expr->alias !== null && $expr->alias !== '') {
            return $this->unquote($expr->alias);
        }

        if ($expr->column !== null && $expr->column !== '') {
            if ($expr->column === '*') {
                return null;
            }

            return $this->unquote($expr->column);
        }

        $rendered = trim((string) $expr->expr);
        if ($rendered === '' || $rendered === '*') {
            return null;
        }

        return $rendered;
    }

    private function unquote(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return $identifier;
        }

        $first = $identifier[0];
        $last = $identifier[strlen($identifier) - 1];
        if (($first === '`' && $last === '`') || ($first === '"' && $last === '"')) {
            return substr($identifier, 1, -1);
        }

        return $identifier;
    }

    private function fallbackRow(): Type
    {
        return new ArrayType(new StringType(), new MixedType());
    }
}
