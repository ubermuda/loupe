<?php

declare(strict_types=1);

namespace App\Tests\Audit\Scheduler;

use App\Audit\Scheduler\PurgeAuditLogTask;
use App\Tests\Support\ScheduledTasks;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The tick's wiring is entirely in an attribute and a compiler pass, so nothing
 * in the purger's own tests would notice it going missing.
 */
final class PurgeAuditLogTaskTest extends KernelTestCase
{
    public function test_the_purge_is_registered_on_the_default_schedule_every_hour(): void
    {
        self::bootKernel();

        self::assertSame(
            '45 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[PurgeAuditLogTask::class] ?? null,
        );
    }

    public function test_the_task_purges_an_expired_record_when_invoked(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->seed($connection, 'audit.expired');
        self::assertSame(['audit.expired'], $this->operations($connection));

        $task = self::getContainer()->get(PurgeAuditLogTask::class);
        self::assertInstanceOf(PurgeAuditLogTask::class, $task);
        $task();

        self::assertSame([], $this->operations($connection));
    }

    private function seed(Connection $connection, string $operation): void
    {
        $connection->executeStatement(
            'INSERT INTO audit_log (id, operation, outcome, category, channel, occurred_at, context)'
                .' VALUES (:id, :operation, :outcome, :category, :channel, :occurredAt, :context)',
            [
                'id' => (string) Uuid::v7(),
                'operation' => $operation,
                'outcome' => 'success',
                'category' => 'domain',
                'channel' => 'cron',
                'occurredAt' => new \DateTimeImmutable('-10 years')->format('Y-m-d H:i:s.u'),
                'context' => '{}',
            ],
        );
    }

    /** @return list<string> */
    private function operations(Connection $connection): array
    {
        return array_map(
            strval(...),
            $connection->fetchFirstColumn('SELECT operation FROM audit_log ORDER BY operation'),
        );
    }
}
