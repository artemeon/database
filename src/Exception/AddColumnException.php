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

namespace Artemeon\Database\Exception;

use Artemeon\Database\Schema\DataType;

class AddColumnException extends \Exception
{
    public function __construct(
        string                    $message,
        private readonly string   $table,
        private readonly string   $column,
        private readonly DataType $dataType,
        private readonly ?bool    $null = null,
        private readonly ?string  $default = null,
        ?\Throwable               $previous = null
    )
    {
        parent::__construct($message, 0, $previous);
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getDataType(): string
    {
        return $this->dataType->value;
    }

    public function getNull(): ?bool
    {
        return $this->null;
    }

    public function getDefault(): ?string
    {
        return $this->default;
    }
}
