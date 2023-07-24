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

namespace Artemeon\Database\Tests;

use Artemeon\Database\Exception\ConnectionException;
use Artemeon\Database\Exception\QueryException;

class ConnectionStringLengthTest extends ConnectionTestCase
{
    /**
     * @throws QueryException
     * @throws ConnectionException
     */
    public function testStringLength(): void
    {
        $query = 'SELECT ' . $this->getConnection()->getStringLengthExpression('temp_char10') . ' AS val FROM ' . self::TEST_TABLE_NAME
            . ' WHERE temp_char10 = ?';
        $row = $this->getConnection()->getPRow($query, ['char10-3']);

        $this->assertEquals(8, (int)$row['val']);
    }
}
