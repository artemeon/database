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

namespace Artemeon\Database\Tests\Doctrine;

use Artemeon\Database\Tests\ConnectionTestCase;

/**
 * @internal
 */
class QueryBuilderTest extends ConnectionTestCase
{
    public function testSelectAllReturnsSeededRows(): void
    {
        $qb = $this->getConnection()->createQueryBuilder()
            ->select('temp_char10')
            ->from(self::TEST_TABLE_NAME)
            ->orderBy('temp_bigint', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $this->assertCount(50, $rows);
        $this->assertSame('char10-1', $rows[0]['temp_char10']);
        $this->assertSame('char10-10', $rows[9]['temp_char10']);
    }

    public function testSelectWithPositionalParameter(): void
    {
        $qb = $this->getConnection()->createQueryBuilder()
            ->select('temp_int', 'temp_char10')
            ->from(self::TEST_TABLE_NAME)
            ->where('temp_char10 = ?')
            ->setParameter(0, 'char10-3');

        $row = $qb->executeQuery()->fetchAssociative();

        $this->assertIsArray($row);
        $this->assertSame('char10-3', $row['temp_char10']);
        $this->assertEquals(123459, $row['temp_int']);
    }

    public function testSelectWithNamedParameterGetsRewrittenByDoctrine(): void
    {
        $qb = $this->getConnection()->createQueryBuilder()
            ->select('temp_char20')
            ->from(self::TEST_TABLE_NAME)
            ->where('temp_char10 = :needle')
            ->setParameter('needle', 'char10-7');

        $value = $qb->executeQuery()->fetchOne();

        $this->assertSame('char20-7', $value);
    }

    public function testUpdateThroughQueryBuilderReturnsAffectedRows(): void
    {
        $qb = $this->getConnection()->createQueryBuilder()
            ->update(self::TEST_TABLE_NAME)
            ->set('temp_char100', '?')
            ->where('temp_char10 = ?')
            ->setParameter(0, 'updated-via-qb')
            ->setParameter(1, 'char10-5');

        $affected = $qb->executeStatement();

        $this->assertSame(1, $affected);

        $check = $this->getConnection()->fetchOne(
            'SELECT temp_char100 FROM ' . self::TEST_TABLE_NAME . ' WHERE temp_char10 = ?',
            ['char10-5'],
        );
        $this->assertSame('updated-via-qb', $check);
    }
}
