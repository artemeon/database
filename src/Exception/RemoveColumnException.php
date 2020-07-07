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

class RemoveColumnException extends \Exception
{
    /**
     * @var string
     */
    private $table;

    /**
     * @var string
     */
    private $column;

    public function __construct(string $message, string $table, string $column, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->table = $table;
        $this->column = $column;
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
}
