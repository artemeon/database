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

use Artemeon\Database\Driver\Oci8Driver;
use Artemeon\Database\Driver\PostgresDriver;
use Artemeon\Database\Schema\DataType;

class ConnectionRoundTest extends ConnectionTestCase
{
    public function testRound()
    {
        $query = 'SELECT ROUND(0.33333333333333, 8) AS val FROM ' . self::TEST_TABLE_NAME;
        $row = $this->getConnection()->getPRow($query);

        $this->assertEqualsWithDelta(round(1 / 3, 8), (float) $row['val'], 0.0001);
    }
}
