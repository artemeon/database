<?php

declare(strict_types=1);

namespace Kajona\System\System\Db\Schema;

use Kajona\System\System\Database;

/**
 * Base information about a database table
 * @package Kajona\System\System\Db\Schema
 * @author stefan.idler@artemeon.de
 */
class Table implements \JsonSerializable
{
    private $name = "";

    /** @var TableColumn[] */
    private $columns = [];

    /** @var TableIndex[] */
    private $indexes = [];

    /** @var TableKey[] */
    private $primaryKeys = [];

    /**
     * Table constructor.
     * @param string $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Generates the tables as configured by the current object model using the passed Database instance
     * @param Database $connection
     * @throws \Kajona\System\System\Exception
     */
    public function generateTable(Database $connection)
    {
        $columns = [];
        foreach ($this->getColumns() as $colDef) {
            $columns[$colDef->getName()] = [$colDef->getInternalType(), $colDef->isNullable()];
        }

        $primary = [];
        foreach ($this->getPrimaryKeys() as $keyDef) {
            $primary[] = $keyDef->getName();
        }

        $connection->createTable($this->name, $columns, $primary);

        foreach ($this->getIndexes() as $indexDef) {
            $connection->addIndex($this->name, $indexDef);
        }
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize()
    {
        return [
            "name" => $this->getName(),
            "indexes" => $this->getIndexes(),
            "keys" => $this->getPrimaryKeys(),
            "columns" => $this->getColumns()
        ];
    }

    /**
     * Fetches a single table col info
     * @param $name
     * @return TableColumn|null
     */
    public function getColumnByName($name): ?TableColumn
    {
        foreach ($this->columns as $col) {
            if ($col->getName() == $name) {
                return $col;
            }
        }
        return null;
    }


    /**
     * @param TableColumn $col
     */
    public function addColumn(TableColumn $col)
    {
        $this->columns[] = $col;
    }

    /**
     * @param TableIndex $index
     */
    public function addIndex(TableIndex $index)
    {
        $this->indexes[] = $index;
    }

    /**
     * @param TableKey $key
     */
    public function addPrimaryKey(TableKey $key)
    {
        $this->primaryKeys[] = $key;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name)
    {
        $this->name = $name;
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
    public function setColumns(array $columns)
    {
        $this->columns = $columns;
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
    public function setIndexes(array $indexes)
    {
        $this->indexes = $indexes;
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
    public function setPrimaryKeys(array $primaryKeys)
    {
        $this->primaryKeys = $primaryKeys;
    }

    /**
     * @return array
     */
    public function getColumnNames(): array
    {
        return array_map(function(TableColumn $column){
            return $column->getName();
        }, $this->columns);
    }
}
