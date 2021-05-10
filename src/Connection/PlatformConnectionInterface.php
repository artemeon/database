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

namespace Artemeon\Database\Connection;

/**
 * @since 7.3
 */
interface PlatformConnectionInterface
{
    /**
     * Appends a limit expression to the provided query
     *
     * @param string $strQuery
     * @param int $intStart
     * @param int $intEnd
     * @return string
     */
    public function appendLimitExpression($strQuery, $intStart, $intEnd);

    /**
     * @param array $parts
     * @return string
     */
    public function getConcatExpression(array $parts);

    /**
     * @param array $parts
     * @return string
     */
    public function getLeastExpression(array $parts): string;

    /**
     * Builds a query expression to retrieve a substring of the given column name or value.
     *
     * The offset of the substring inside of the value must be given as 1-based index. If a length is given, only up to
     * this number of characters are extracted; if no length is given, everything to the end of the value is extracted.
     * *Note*: Negative offsets or lengths are not guaranteed to work across different database drivers.
     */
    public function getSubstringExpression(string $value, int $offset, ?int $length): string;
}
