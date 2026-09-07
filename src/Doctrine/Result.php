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

namespace Artemeon\Database\Doctrine;

use Doctrine\DBAL\Driver\Result as DriverResult;

/**
 * Buffered DBAL driver result backed by a pre-fetched row array.
 *
 * The Artemeon connection materialises full result sets, so the adapter
 * owns the rows up-front and replays them through the DBAL fetch API.
 */
final class Result implements DriverResult
{
    private int $position = 0;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private array $rows,
        private readonly int $affectedRows,
    ) {
    }

    public function fetchNumeric(): array | false
    {
        $row = $this->rows[$this->position] ?? null;
        if ($row === null) {
            return false;
        }

        $this->position++;

        return array_values($row);
    }

    public function fetchAssociative(): array | false
    {
        $row = $this->rows[$this->position] ?? null;
        if ($row === null) {
            return false;
        }

        $this->position++;

        return $row;
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();
        if ($row === false) {
            return false;
        }

        return $row[0] ?? null;
    }

    public function fetchAllNumeric(): array
    {
        $out = [];
        while (($row = $this->fetchNumeric()) !== false) {
            $out[] = $row;
        }

        return $out;
    }

    public function fetchAllAssociative(): array
    {
        $out = [];
        while (($row = $this->fetchAssociative()) !== false) {
            $out[] = $row;
        }

        return $out;
    }

    public function fetchFirstColumn(): array
    {
        $out = [];
        while (($row = $this->fetchNumeric()) !== false) {
            $out[] = $row[0];
        }

        return $out;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }

    public function columnCount(): int
    {
        $first = $this->rows[0] ?? null;

        return $first === null ? 0 : count($first);
    }

    public function free(): void
    {
        $this->rows = [];
        $this->position = 0;
    }
}
