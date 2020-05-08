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
 *
 * @package module_system
 * @author sidler@mulchprod.de
 * @since 4.5
 */
class DataType
{
    const STR_TYPE_INT = "int";

    const STR_TYPE_BIGINT = "long";
    /**
     * @deprecated please use bigint
     */
    const STR_TYPE_LONG = "long";

    const STR_TYPE_FLOAT = "double";
    /**
     * @deprecated please use float
     */
    const STR_TYPE_DOUBLE = "double";

    const STR_TYPE_CHAR10 = "char10";
    const STR_TYPE_CHAR20 = "char20";
    const STR_TYPE_CHAR100 = "char100";
    const STR_TYPE_CHAR254 = "char254";
    const STR_TYPE_CHAR500 = "char500";
    const STR_TYPE_TEXT = "text";
    const STR_TYPE_LONGTEXT = "longtext";
}
