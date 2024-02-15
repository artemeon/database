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
    private string $table;

    /**
     * @var string
     */
    private $oldColumnName;

    /**
     * @var string
     */
    private $newColumnName;

    private DataType $newDataType;

    public function __construct(string $message, string $table, string $oldColumnName, string $newColumnName, DataType $newDataType, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->table = $table;
        $this->oldColumnName = $oldColumnName;
        $this->newColumnName = $newColumnName;
        $this->newDataType = $newDataType;
    }

    /**
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return string
     */
    public function getOldColumnName(): string
    {
        return $this->oldColumnName;
    }

    /**
     * @return string
     */
    public function getNewColumnName(): string
    {
        return $this->newColumnName;
    }

    public function getNewDataType(): DataType
    {
        return $this->newDataType;
    }
}
