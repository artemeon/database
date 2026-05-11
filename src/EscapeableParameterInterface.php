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

namespace Artemeon\Database;

use Stringable;

interface EscapeableParameterInterface
{
    public function isEscape(): bool;

    /**
     * @return scalar|Stringable|null
     */
    public function getValue(): mixed;
}
