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

enum StringBackedEnum: string
{
    case Foo = 'foo';
    case WithHtml = '<a>';
    case WithQuote = "O'Brien";
}
