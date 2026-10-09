<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260927141409;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260927141409.php';

final class ForgePullRequestsBackfillMigrationTest extends KernelTestCase
{
    public function test_each_distinct_github_pull_request_of_a_project_gets_one_open_unread_row(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = new User(fullName: 'Riley', email: 'forge-backfill@example.com', password: 'x');
        $project = new Project($owner, 'forge-backfill');
        $other = new Project($owner, 'forge-backfill-other');
        $em->persist($owner);
        $em->persist($project);
        $em->persist($other);
        $first = $this->card($em, $project, 1);
        $second = $this->card($em, $project, 2);
        $elsewhere = $this->card($em, $other, 1);
        $em->persist(new CardPullRequest($first, 'https://github.com/Acme/Widgets/pull/7', Forge::GitHub, 'Acme/Widgets', 7));
        $em->persist(new CardPullRequest($second, 'https://github.com/acme/widgets/pull/7', Forge::GitHub, 'acme/widgets', 7));
        $em->persist(new CardPullRequest($second, 'https://github.com/acme/widgets/pull/8', Forge::GitHub, 'acme/widgets', 8));
        $em->persist(new CardPullRequest($elsewhere, 'https://github.com/acme/widgets/pull/7', Forge::GitHub, 'acme/widgets', 7));
        $em->persist(new CardPullRequest($first, 'https://gitlab.com/acme/widgets/-/merge_requests/9', Forge::GitLab, 'acme/widgets', 9));
        $em->persist(new CardPullRequest($first, 'https://example.com/pr'));
        $em->flush();
        $connection = $em->getConnection();
        $this->dropForeignKeysTo($connection, 'forge_pull_requests');

        foreach (['down', 'up'] as $direction) {
            $migration = new Version20260927141409($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }

        self::assertEquals(
            [
                ['project_id' => (string) $project->id, 'forge' => 'github', 'repository' => 'acme/widgets', 'number' => 7],
                ['project_id' => (string) $project->id, 'forge' => 'github', 'repository' => 'acme/widgets', 'number' => 8],
                ['project_id' => (string) $other->id, 'forge' => 'github', 'repository' => 'acme/widgets', 'number' => 7],
            ],
            $connection->fetchAllAssociative(
                'SELECT project_id, forge, repository, number FROM forge_pull_requests WHERE project_id IN (?, ?) ORDER BY project_id = ? DESC, number',
                [(string) $project->id, (string) $other->id, (string) $project->id],
            ),
        );
        self::assertSame(
            [['state' => 'open', 'refreshed_at' => null, 'mergeability' => 'unknown', 'failed_checks' => '[]']],
            $connection->fetchAllAssociative(
                'SELECT DISTINCT state, refreshed_at, mergeability, failed_checks::text AS failed_checks FROM forge_pull_requests WHERE project_id IN (?, ?)',
                [(string) $project->id, (string) $other->id],
            ),
        );
    }

    // Later tables reference forge_pull_requests, so down() cannot drop it; the test transaction restores them.
    private function dropForeignKeysTo(Connection $connection, string $table): void
    {
        $constraints = $connection->fetchAllAssociative(
            "SELECT conrelid::regclass::text AS owner, conname FROM pg_constraint WHERE contype = 'f' AND confrelid = ?::regclass",
            [$table],
        );
        foreach ($constraints as $constraint) {
            $connection->executeStatement(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $constraint['owner'], $constraint['conname']));
        }
    }

    private function card(EntityManagerInterface $em, Project $project, int $number): Card
    {
        $column = new BoardColumn($project, 'Backlog '.$number, 'backlog-'.$number, $number);
        $card = new Card(project: $project, column: $column, title: 'Linked', body: 'Body', number: $number);
        $em->persist($column);
        $em->persist($card);

        return $card;
    }
}
