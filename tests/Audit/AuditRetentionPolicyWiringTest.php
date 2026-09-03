<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\FeatureFlagAuditRetentionPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditRetentionPolicyInterface;
use Ubermuda\AuditBundle\Scheduler\PurgeAuditLogTask;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * The alias is what makes the window editable at runtime. Without it the
 * container autowires the package's parameter-only policy, everything still
 * works, and the admin flag does nothing.
 */
final class AuditRetentionPolicyWiringTest extends KernelTestCase
{
    public function test_the_container_answers_the_port_with_the_flag_backed_policy(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            FeatureFlagAuditRetentionPolicy::class,
            self::getContainer()->get(AuditRetentionPolicyInterface::class),
        );
    }

    /** Seeding never reaches an installed instance, so no flag row must still mean 180 days. */
    public function test_an_instance_with_no_flag_row_still_keeps_one_hundred_and_eighty_days(): void
    {
        self::bootKernel();

        $policy = self::getContainer()->get(AuditRetentionPolicyInterface::class);
        self::assertInstanceOf(AuditRetentionPolicyInterface::class, $policy);

        self::assertSame(180, $policy->retentionDays());
    }

    /**
     * A ten-year-old record that the default window deletes, kept by a flag both
     * purge paths must read. Two windows would show up as one path removing it.
     */
    public function test_the_hourly_sweep_and_the_manual_command_read_the_same_window(): void
    {
        $kernel = self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();

        $em->persist(new FeatureFlag(FeatureFlagAuditRetentionPolicy::FLAG, FeatureFlagType::Int, 36_500));
        $em->flush();

        $this->seed($connection, 'audit.a_decade_old');
        $this->seed($connection, 'audit.also_a_decade_old');

        $tester = new CommandTester(new Application($kernel)->find('audit:purge'));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Purged 0 audit record(s).', $tester->getDisplay());

        $task = self::getContainer()->get(PurgeAuditLogTask::class);
        self::assertInstanceOf(PurgeAuditLogTask::class, $task);
        $task();

        self::assertSame(
            ['audit.a_decade_old', 'audit.also_a_decade_old'],
            $this->operations($connection),
        );
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
                'channel' => 'system',
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
