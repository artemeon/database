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

class AddColumnException extends \Exception
{
    /**
     * @var string
     */
    private $table;

    /**
     * @var string
     */
    private $column;

    /**
     * @var string
     */
    private $dataType;

    /**
     * @var bool|null
     */
    private $null;

    /**
     * @var string|null
     */
    private $default;

    public function __construct(string $message, string $table, string $column, string $dataType, ?bool $null = null, ?string $default = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->table = $table;
        $this->column = $column;
        $this->dataType = $dataType;
        $this->null = $null;
        $this->default = $default;
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
    public function getColumn(): string
    {
        return $this->column;
    }

    /**
     * @return string
     */
    public function getDataType(): string
    {
        return $this->dataType;
    }

    /**
     * @return bool|null
     */
    public function getNull(): ?bool
    {
        return $this->null;
    }

    /**
     * @return string|null
     */
    public function getDefault(): ?string
    {
        return $this->default;
    }
}
