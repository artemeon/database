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

namespace Artemeon\Database\Schema;

use JsonSerializable;

/**
 * Base information about a database table.
 */
class Table implements JsonSerializable
{
    private string $name;

    /** @var TableColumn[] */
    private array $columns = [];

    /** @var TableIndex[] */
    private array $indexes = [];

    /** @var TableKey[] */
    private array $primaryKeys = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->getName(),
            'indexes' => $this->getIndexes(),
            'keys' => $this->getPrimaryKeys(),
            'columns' => $this->getColumns(),
        ];
    }

    /**
     * Fetches a single table col info.
     */
    public function getColumnByName(string $name): ?TableColumn
    {
        foreach ($this->columns as $col) {
            if ($col->getName() === $name) {
                return $col;
            }
        }

        return null;
    }

    public function addColumn(TableColumn $column): self
    {
        $this->columns[] = $column;

        return $this;
    }

    public function addIndex(TableIndex $index): self
    {
        $this->indexes[] = $index;

        return $this;
    }

    public function addPrimaryKey(TableKey $key): self
    {
        $this->primaryKeys[] = $key;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return TableColumn[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @param TableColumn[] $columns
     */
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @return TableIndex[]
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * @param TableIndex[] $indexes
     */
    public function setIndexes(array $indexes): self
    {
        $this->indexes = $indexes;

        return $this;
    }

    /**
     * @return TableKey[]
     */
    public function getPrimaryKeys(): array
    {
        return $this->primaryKeys;
    }

    /**
     * @param TableKey[] $primaryKeys
     */
    public function setPrimaryKeys(array $primaryKeys): self
    {
        $this->primaryKeys = $primaryKeys;

        return $this;
    }

    public function getColumnNames(): array
    {
        return array_map(static fn (TableColumn $column) => $column->getName(), $this->columns);
    }
}
