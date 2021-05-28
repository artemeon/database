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

/**
 * @since 7.3
 * @deprecated
 */
interface EscapeableParameterInterface
{
    /**
     * @return bool
     */
    public function isEscape(): bool;

    /**
     * @return mixed
     */
    public function getValue();
}
