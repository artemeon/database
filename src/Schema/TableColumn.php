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

/**
 * Base information about a tables column
 * @package Kajona\System\System\Db\Schema
 * @author stefan.idler@artemeon.de
 */
class TableColumn implements \JsonSerializable
{
    private $name = "";
    private $internalType = "";
    private $databaseType = "";
    private $nullable = true;

    /**
     * TableColumn constructor.
     * @param string $name
     */
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
            "name" => $this->getName(),
            "internalType" => $this->getInternalType(),
            "databaseType" => $this->getDatabaseType(),
            "nullable" => $this->isNullable()
        ];
    }

    /**
     * @return bool
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * @param bool $nullable
     */
    public function setNullable(bool $nullable)
    {
        $this->nullable = $nullable;
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
     * @return string
     */
    public function getInternalType(): string
    {
        return $this->internalType;
    }

    /**
     * @param string $internalType
     */
    public function setInternalType(string $internalType)
    {
        $this->internalType = $internalType;
    }

    /**
     * @return string
     */
    public function getDatabaseType(): string
    {
        return $this->databaseType;
    }

    /**
     * @param string $databaseType
     */
    public function setDatabaseType(string $databaseType)
    {
        $this->databaseType = $databaseType;
    }


}