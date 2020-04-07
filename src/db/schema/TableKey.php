<?php

declare(strict_types=1);

namespace Kajona\System\System\Db\Schema;

/**
 * Base information about a tables primary key
 * @package Kajona\System\System\Db\Schema
 * @author stefan.idler@artemeon.de
 */
class TableKey implements \JsonSerializable
{
    private $name = "";

    /**
     * TableKey constructor.
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
        return ["name" => $this->getName()];
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



}