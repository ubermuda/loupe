<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit;

use App\Module\Audit\AuditLogPurger;
use App\Module\Audit\AuditRetentionPolicyInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * The statement-shape half of the purger. What it removes from a real table is
 * in App\Tests\Module\Audit\AuditLogPurgerPersistenceTest.
 */
final class AuditLogPurgerTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    /**
     * The reason the purge goes through the DBAL rather than the EntityManager:
     * a retention sweep's row count is unbounded, so it must not scale with it.
     */
    public function test_the_purge_is_one_statement_however_many_rows_it_removes(): void
    {
        $this->connection->expects($this->once())->method('executeStatement')->willReturn(10_000);
        $this->connection->expects($this->never())->method('fetchFirstColumn');
        $this->connection->expects($this->never())->method('fetchAllAssociative');

        self::assertSame(10_000, $this->purger()->purge());
    }

    public function test_an_empty_window_is_still_one_statement_and_removes_nothing(): void
    {
        $this->connection->expects($this->once())->method('executeStatement')->willReturn(0);

        self::assertSame(0, $this->purger()->purge());
    }

    public function test_the_cutoff_is_the_retention_window_behind_the_clock(): void
    {
        $sql = null;
        $params = null;
        $this->connection->expects($this->once())->method('executeStatement')
            ->willReturnCallback(function (string $statement, array $bound) use (&$sql, &$params): int {
                $sql = $statement;
                $params = $bound;

                return 0;
            });

        $this->purger(now: '2026-08-27 12:00:00.500000', retentionDays: 30)->purge();

        self::assertSame('DELETE FROM audit_log WHERE occurred_at < :cutoff', $sql);
        self::assertSame(['cutoff' => '2026-07-28 12:00:00.500000'], $params);
    }

    private function purger(string $now = '2026-08-27 12:00:00', int $retentionDays = 180): AuditLogPurger
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable($now));

        $retention = $this->createStub(AuditRetentionPolicyInterface::class);
        $retention->method('retentionDays')->willReturn($retentionDays);

        return new AuditLogPurger($this->connection, $clock, $retention);
    }
}
