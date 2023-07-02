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

use Artemeon\Database\Exception\QueryException;
use Artemeon\Database\MockConnection;

class MockConnectionTest extends ConnectionTestCase
{
    /**
     * @throws QueryException
     */
    public function testConnection(): void
    {
        $connection = new MockConnection();
        $connection->addRow(['title' => 'foo']);
        $connection->addRow(['title' => 'bar']);

        $this->assertEquals([['title' => 'foo'], ['title' => 'bar']], $connection->getPArray(''));
        $this->assertEquals(['title' => 'foo'], $connection->getPRow(''));
        $this->assertEquals(['title' => 'foo'], $connection->selectRow('', [], []));
        $this->assertEquals([['title' => 'foo'], ['title' => 'bar']], iterator_to_array($connection->getGenerator('')));
    }
}
