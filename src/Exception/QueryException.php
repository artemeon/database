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

use Artemeon\Database\EscapeableParameterInterface;
use BackedEnum;
use Exception;
use Stringable;
use Throwable;

class QueryException extends Exception
{
    /**
     * @param list<BackedEnum|EscapeableParameterInterface|scalar|Stringable|null> $params
     */
    public function __construct(string $message, private readonly string $query, private readonly array $params, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * @return list<BackedEnum|EscapeableParameterInterface|scalar|Stringable|null>
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
