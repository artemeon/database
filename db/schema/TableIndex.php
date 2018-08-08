<?php

declare(strict_types=1);

namespace Kajona\System\System\Db\Schema;

/**
 * Base information about a tables index
 * @package Kajona\System\System\Db\Schema
 * @author stefan.idler@artemeon.de
 */
class TableIndex implements \JsonSerializable
{
    private $name = "";
    private $description = "";

    /**
     * TableIndex constructor.
     * @param string $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize()
    {
        return [
            "name" => $this->getName(),
            "description" => $this->getDescription()
        ];
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
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param string $description
     */
    public function setDescription(string $description)
    {
        $this->description = $description;
    }



}