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

namespace Artemeon\Database\Tests\Benchmark;

use Artemeon\Database\DoctrineConnectionInterface;

class ConnectionBench
{
    /**
     * @Revs(1000)
     * @Iterations(5)
     */
    public function benchFetchAllAssociative(): void
    {
        $this->getConnection()->fetchAllAssociative('SELECT * FROM ' . TEST_TABLE_NAME);
    }

    /**
     * @Revs(1000)
     * @Iterations(5)
     */
    public function benchFetchAssociative(): void
    {
        $this->getConnection()->fetchAssociative('SELECT * FROM ' . TEST_TABLE_NAME);
    }

    /**
     * @Revs(1000)
     * @Iterations(5)
     */
    public function benchFetchFirstColumn(): void
    {
        $this->getConnection()->fetchFirstColumn('SELECT * FROM ' . TEST_TABLE_NAME);
    }

    /**
     * @Revs(1000)
     * @Iterations(5)
     */
    public function benchFetchOne(): void
    {
        $this->getConnection()->fetchOne('SELECT COUNT(*) AS cnt FROM ' . TEST_TABLE_NAME);
    }

    protected function getConnection(): DoctrineConnectionInterface
    {
        global $connection;
        return $connection;
    }
}
