<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\Command\Console;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Full-stack run of the manual seam: the command resolves the real container
 * purger, so the configured retention window and the real table are in play.
 */
final class PurgeAuditLogCommandTest extends KernelTestCase
{
    public function test_it_reports_how_many_records_it_removed(): void
    {
        $kernel = self::bootKernel();
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->seed($connection, 'audit.expired_one');
        $this->seed($connection, 'audit.expired_two');

        $tester = new CommandTester(new Application($kernel)->find('audit:purge'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Purged 2 audit record(s).', $tester->getDisplay());

        // A second run finds the window empty, so the count is the rows removed
        // rather than the rows that were there.
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Purged 0 audit record(s).', $tester->getDisplay());
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
}
