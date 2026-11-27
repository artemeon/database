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

use Artemeon\Database\Connection;
use Artemeon\Database\Exception\CommitException;
use Artemeon\Database\Schema\DataType;
use Exception;
use Override;

/**
 * @internal
 */
class ConnectionTransactionBalanceTest extends ConnectionTestCase
{
    private const string TABLE_NAME = 'agp_temp_autotest_balance';

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->getConnection();
        $this->connection->createTable(self::TABLE_NAME, ['temp_id' => [DataType::CHAR20, false]], ['temp_id']);
        self::getLogger()->records = [];
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->connection->dropTable(self::TABLE_NAME);

        parent::tearDown();
    }

    public function testCommitWithoutOpenTransactionKeepsTheNextTransactionIntact(): void
    {
        $this->connection->commit();

        $this->connection->beginTransaction();
        $this->insertRow();
        $this->connection->rollBack();

        $this->assertSame(0, $this->countRows());
    }

    public function testRollBackWithoutOpenTransactionKeepsTheNextCommitIntact(): void
    {
        $this->connection->rollBack();

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->insertRow();
        $this->connection->commit();
        $this->connection->commit();

        $this->assertSame(1, $this->countRows());
    }

    public function testCommitWithoutOpenTransactionLogsAWarningWithTheCaller(): void
    {
        $this->connection->commit();

        $this->assertWarningPointsTo('/^Commit without an open transaction/', __FUNCTION__);
    }

    public function testRollBackWithoutOpenTransactionLogsAWarningWithTheCaller(): void
    {
        $this->connection->rollBack();

        $this->assertWarningPointsTo('/^Rollback without an open transaction/', __FUNCTION__);
    }

    public function testTransactionalDoesNotRollBackAfterItsOwnCommitFailed(): void
    {
        try {
            $this->connection->transactional(function (): void {
                $this->connection->beginTransaction();
                $this->connection->rollBack();
            });
            $this->fail('The nested rollback must make the commit fail.');
        } catch (CommitException) {
        }

        $this->assertSame([], $this->getWarnings());
    }

    public function testFailingDriverRollbackOfADirtyCommitKeepsTheNextCommitIntact(): void
    {
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->connection->rollBack();
        // Ends the real transaction behind the connection's back, so that SQLite rejects the driver rollback
        // of the dirty commit below. That rejected rollback is the case this test covers.
        $this->connection->_pQuery('COMMIT');

        // The SQLite3 extension also raises a PHP warning for the rejected statement.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $this->connection->commit();
        } catch (Exception) {
            // Depending on the driver, the rejected rollback or the CommitException ends the commit.
        } finally {
            restore_error_handler();
        }

        $this->connection->beginTransaction();
        $this->insertRow();
        $this->connection->commit();

        $this->assertSame(1, $this->countRows());
    }

    private function assertWarningPointsTo(string $messagePattern, string $caller): void
    {
        $warnings = $this->getWarnings();

        $this->assertCount(1, $warnings);
        $this->assertMatchesRegularExpression($messagePattern, $warnings[0]['message']);
        $this->assertIsString($warnings[0]['context']['trace'] ?? null);
        $this->assertStringContainsString($caller, $warnings[0]['context']['trace']);
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    private function getWarnings(): array
    {
        return array_values(array_filter(
            self::getLogger()->records,
            static fn (array $record): bool => $record['level'] === 'warning',
        ));
    }

    private function insertRow(): void
    {
        $this->connection->insert(self::TABLE_NAME, ['temp_id' => $this->generateSystemid()]);
    }

    private function countRows(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE_NAME);
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
