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

namespace Artemeon\Database\Tests\Fixtures;

use Artemeon\Database\EscapeableParameterInterface;
use Stringable;

final class EscapeableValue implements EscapeableParameterInterface
{
    /**
     * @param scalar|Stringable|null $value
     */
    public function __construct(private readonly mixed $value)
    {
    }

    public function isEscape(): bool
    {
        return true;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
