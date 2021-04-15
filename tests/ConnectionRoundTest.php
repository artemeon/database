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

class ConnectionRoundTest extends ConnectionTestCase
{
    public function testRound()
    {
        $query = 'SELECT ROUND(1.33333333333333, 8) AS val FROM ' . self::TEST_TABLE_NAME;
        $row = $this->getConnection()->getPRow($query);

        $this->assertEquals('1.33333333', substr((string) $row['val'], 0, 10));
        $this->assertEqualsWithDelta(1 + round(1 / 3, 8), (float) $row['val'], 0.0001);
    }

    public function testRoundUp()
    {
        $query = 'SELECT ROUND(1.16666666666666, 8) AS val FROM ' . self::TEST_TABLE_NAME;
        $row = $this->getConnection()->getPRow($query);

        $this->assertEquals('1.16666667', substr((string) $row['val'], 0, 10));
        $this->assertEqualsWithDelta(1 + round(1 / 6, 8), (float) $row['val'], 0.0001);
    }
}

