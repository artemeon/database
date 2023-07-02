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

use Exception;
use Throwable;

class QueryException extends Exception
{
    private string $query;

    private array $params;

    public function __construct(string $message, string $query, array $params, Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->query = $query;
        $this->params = $params;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getParams(): array
    {
        return $this->params;
    }
}
