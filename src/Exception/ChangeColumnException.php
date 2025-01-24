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

class ChangeColumnException extends \Exception
{
    public function __construct(
        string $message,
        private readonly string $table,
        private readonly string $oldColumnName,
        private readonly string $newColumnName,
        private readonly DataType $newDataType,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getOldColumnName(): string
    {
        return $this->oldColumnName;
    }

    public function getNewColumnName(): string
    {
        return $this->newColumnName;
    }

    public function getNewDataType(): DataType
    {
        return $this->newDataType;
    }
}
