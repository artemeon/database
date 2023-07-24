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
 * Base information about a tables primary key
 */
class TableKey implements JsonSerializable
{
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return ['name' => $this->getName()];
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
}
