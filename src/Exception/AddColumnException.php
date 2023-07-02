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
    private string $table;

    private string $column;

    private DataType $dataType;

    private ?bool $null;

    private ?string $default;

    public function __construct(string $message, string $table, string $column, DataType $dataType, ?bool $null = null, ?string $default = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->table = $table;
        $this->column = $column;
        $this->dataType = $dataType;
        $this->null = $null;
        $this->default = $default;
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
