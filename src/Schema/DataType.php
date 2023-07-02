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

namespace Artemeon\Database\Schema;

/**
 * List of possible data-types usable when generating new tables / updating tables.
 */
enum DataType: string
{
    case INT = 'int';
    case BIGINT = 'long';
    case FLOAT = 'double';
    case CHAR10 = 'char10';
    case CHAR20 = 'char20';
    case CHAR100 = 'char100';
    case CHAR254 = 'char254';
    case CHAR500 = 'char500';
    case TEXT = 'text';
    case LONGTEXT = 'longtext';
}
