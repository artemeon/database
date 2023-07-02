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
 * Base information about a table's column.
 */
class TableColumn implements JsonSerializable
{
    private string $name;
    private ?DataType $internalType = null;
    private string $databaseType = '';
    private bool $nullable = true;

    public static function make(string $name): self
    {
        return new self($name);
    }

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
            'internalType' => $this->getInternalType()?->value ?? '',
            'databaseType' => $this->getDatabaseType(),
            'nullable' => $this->isNullable(),
        ];
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function setNullable(bool $nullable): self
    {
        $this->nullable = $nullable;

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

    public function getInternalType(): ?DataType
    {
        return $this->internalType;
    }

    public function setInternalType(?DataType $internalType): self
    {
        $this->internalType = $internalType;

        return $this;
    }

    public function getDatabaseType(): string
    {
        return $this->databaseType;
    }

    public function setDatabaseType(string $databaseType): self
    {
        $this->databaseType = $databaseType;

        return $this;
    }
}
