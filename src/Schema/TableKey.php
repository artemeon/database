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
    public function jsonSerialize(): array
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