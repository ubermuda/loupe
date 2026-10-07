<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261006194956;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261006194956.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class ProjectReadinessGuideBackfillMigrationTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    public function test_up_hides_the_guide_only_for_a_project_with_a_card_outside_the_backlog(): void
    {
        $noCards = $this->project(cardsIn: []);
        $backlogOnly = $this->project(cardsIn: ['backlog' => 2]);
        $inProgress = $this->project(cardsIn: ['backlog' => 1, 'in-progress' => 1]);
        $done = $this->project(cardsIn: ['done' => 1]);

        $this->migrate(down: true);
        self::assertFalse($this->projectsHaveTheColumns());
        $this->migrate();

        self::assertNull($this->hiddenAt($noCards));
        self::assertNull($this->hiddenAt($backlogOnly));
        self::assertNotNull($this->hiddenAt($inProgress));
        self::assertNotNull($this->hiddenAt($done));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projects WHERE agent_first_seen_at IS NOT NULL'));
    }

    public function test_down_drops_both_columns(): void
    {
        $this->migrate(down: true);

        self::assertFalse($this->projectsHaveTheColumns());
    }

    /** @param array<string, int> $cardsIn slug => how many cards that column holds */
    private function project(array $cardsIn): string
    {
        $owner = new User(fullName: 'Riley', email: 'readiness-migration-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'readiness-migration-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);

        $number = 0;
        foreach ($cardsIn as $slug => $count) {
            for ($index = 1; $index <= $count; ++$index) {
                $this->em->persist(new Card(
                    project: $project,
                    column: $this->column($project, $slug),
                    title: \sprintf('Card %s %d', $slug, $index),
                    body: 'Body',
                    number: ++$number,
                    position: $index - 1,
                ));
            }
        }
        $this->em->flush();
        $this->em->clear();

        return (string) $project->id;
    }

    private function hiddenAt(string $projectId): ?string
    {
        $value = $this->connection->fetchOne('SELECT readiness_guide_hidden_at FROM projects WHERE id = :id', ['id' => $projectId]);
        self::assertTrue(null === $value || \is_string($value));

        return $value;
    }

    private function projectsHaveTheColumns(): bool
    {
        return 0 < (int) $this->connection->fetchOne(
            "SELECT count(*) FROM information_schema.columns WHERE table_name = 'projects' AND column_name IN ('readiness_guide_hidden_at', 'agent_first_seen_at')",
        );
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261006194956($this->connection, new NullLogger());
        if ($down) {
            $migration->down(new Schema());
        } else {
            $migration->up(new Schema());
        }
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
