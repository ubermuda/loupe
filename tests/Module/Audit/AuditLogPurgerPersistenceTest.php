<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit;

use App\Module\Audit\AuditLogPurger;
use App\Module\Audit\AuditRetentionPolicyInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The purger against a real table. The clock is fixed here rather than taken
 * from the container, because a row exactly on the cutoff can only be seeded
 * when the cutoff is known to the microsecond.
 */
final class AuditLogPurgerPersistenceTest extends KernelTestCase
{
    private const string NOW = '2026-08-27 12:00:00.000000';
    private const int RETENTION_DAYS = 180;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    public function test_it_removes_a_record_older_than_the_window_and_keeps_one_inside_it(): void
    {
        $cutoff = $this->cutoff();
        $this->seed('audit.old', $cutoff->modify('-1 day'));
        $this->seed('audit.recent', $cutoff->modify('+1 day'));

        self::assertSame(['audit.old', 'audit.recent'], $this->operations());

        self::assertSame(1, $this->purger()->purge());
        self::assertSame(['audit.recent'], $this->operations());
    }

    /** Strict inequality: the cutoff instant is still inside the window. */
    public function test_a_record_exactly_on_the_cutoff_is_kept(): void
    {
        $cutoff = $this->cutoff();
        $this->seed('audit.just_before', $cutoff->modify('-1 microsecond'));
        $this->seed('audit.on_the_cutoff', $cutoff);

        self::assertSame(['audit.just_before', 'audit.on_the_cutoff'], $this->operations());

        self::assertSame(1, $this->purger()->purge());
        self::assertSame(['audit.on_the_cutoff'], $this->operations());
    }

    public function test_one_purge_removes_every_expired_record_at_once(): void
    {
        $cutoff = $this->cutoff();
        for ($i = 1; $i <= 250; ++$i) {
            $this->seed('audit.old_'.$i, $cutoff->modify('-'.$i.' second'));
        }

        self::assertCount(250, $this->operations());

        self::assertSame(250, $this->purger()->purge());
        self::assertSame([], $this->operations());
    }

    private function purger(): AuditLogPurger
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::NOW));

        $retention = $this->createStub(AuditRetentionPolicyInterface::class);
        $retention->method('retentionDays')->willReturn(self::RETENTION_DAYS);

        return new AuditLogPurger($this->connection, $clock, $retention);
    }

    private function cutoff(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW)->sub(new \DateInterval('P'.self::RETENTION_DAYS.'D'));
    }

    private function seed(string $operation, \DateTimeImmutable $occurredAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO audit_log (id, operation, outcome, category, channel, occurred_at, context)'
                .' VALUES (:id, :operation, :outcome, :category, :channel, :occurredAt, :context)',
            [
                'id' => (string) Uuid::v7(),
                'operation' => $operation,
                'outcome' => 'success',
                'category' => 'domain',
                'channel' => 'system',
                'occurredAt' => $occurredAt->format('Y-m-d H:i:s.u'),
                'context' => '{}',
            ],
        );
    }

    /** @return list<string> */
    private function operations(): array
    {
        return array_map(
            strval(...),
            $this->connection->fetchFirstColumn('SELECT operation FROM audit_log ORDER BY occurred_at'),
        );
    }
}
