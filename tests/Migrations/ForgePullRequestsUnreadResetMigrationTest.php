<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260928214808;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260928214808.php';

final class ForgePullRequestsUnreadResetMigrationTest extends KernelTestCase
{
    public function test_a_row_with_no_head_commit_loses_its_read_time_and_a_read_row_keeps_it(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = new User(fullName: 'Riley', email: 'forge-unread-reset@example.com', password: 'x');
        $project = new Project($owner, 'forge-unread-reset');
        $em->persist($owner);
        $em->persist($project);
        $failed = new ForgePullRequest($project, 'github', 'acme/widgets', 7);
        $failed->refreshedAt = new \DateTimeImmutable('2026-09-01 10:00:00');
        $read = new ForgePullRequest($project, 'github', 'acme/widgets', 8);
        $read->headSha = str_repeat('a', 40);
        $read->refreshedAt = new \DateTimeImmutable('2026-09-01 10:00:00');
        $em->persist($failed);
        $em->persist($read);
        $em->flush();
        $connection = $em->getConnection();

        $migration = new Version20260928214808($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertSame(
            [
                ['number' => 7, 'refreshed_at' => null],
                ['number' => 8, 'refreshed_at' => '2026-09-01 10:00:00'],
            ],
            $connection->fetchAllAssociative(
                'SELECT number, refreshed_at FROM forge_pull_requests WHERE project_id = ? ORDER BY number',
                [(string) $project->id],
            ),
        );
    }
}
