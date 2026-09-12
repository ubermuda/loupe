<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260912201432;
use DoctrineMigrations\Version20260912201847;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260912201432.php';
require_once __DIR__.'/../../migrations/Version20260912201847.php';

/**
 * Runs the schema and data migrations against rows written before them, inside
 * the test's own transaction. Postgres rolls DDL back with the rest, so dropping
 * what the bootstrap migrated and running up() again leaves nothing behind.
 */
final class BoardColumnsSeedMigrationTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private const array SEEDED = [
        ['slug' => 'backlog', 'label' => 'board.card.status.backlog', 'position' => 0, 'terminal' => false, 'is_default' => true],
        ['slug' => 'next', 'label' => 'board.card.status.next', 'position' => 1, 'terminal' => false, 'is_default' => false],
        ['slug' => 'in-progress', 'label' => 'board.card.status.in-progress', 'position' => 2, 'terminal' => false, 'is_default' => false],
        ['slug' => 'done', 'label' => 'board.card.status.done', 'position' => 3, 'terminal' => true, 'is_default' => false],
    ];

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

    public function test_every_project_gets_four_columns_and_every_card_its_matching_column(): void
    {
        $owner = $this->owner('board-columns-migration');

        $projectIds = [];
        foreach (['first', 'second'] as $name) {
            $project = new Project($owner, $name.'-'.uniqid());
            $this->em->persist($project);
            $this->seedColumns($project);
            foreach (['backlog', 'next', 'in-progress', 'done'] as $number => $slug) {
                $this->em->persist(new Card(project: $project, column: $this->column($project, $slug), title: $slug, body: 'Body', number: $number + 1));
            }
            $this->em->flush();
            $projectIds[] = (string) $project->id;
        }
        $this->em->clear();

        $this->migrateAgain();

        foreach ($projectIds as $projectId) {
            self::assertSame(self::SEEDED, $this->columns($projectId));

            // Guard: four cards per project, so the comparison below cannot pass on none.
            self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $projectId]));
            self::assertSame(
                [],
                $this->connection->fetchFirstColumn(
                    'SELECT c.id FROM board_cards c LEFT JOIN board_columns k ON k.id = c.column_id
                     WHERE c.project_id = :id AND (k.slug IS DISTINCT FROM c.status OR k.project_id <> c.project_id)',
                    ['id' => $projectId],
                ),
            );
        }
    }

    public function test_a_project_with_no_cards_still_gets_its_columns(): void
    {
        $project = new Project($this->owner('board-columns-empty'), 'empty-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();
        $projectId = (string) $project->id;
        $this->em->clear();

        $this->migrateAgain();

        self::assertSame(self::SEEDED, $this->columns($projectId));
    }

    /** The data migration runs outside a transaction, so a run that failed part way runs again. */
    public function test_running_the_data_migration_twice_changes_nothing(): void
    {
        $project = new Project($this->owner('board-columns-rerun'), 'rerun-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->persist(new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Ship it', body: 'Body', number: 1));
        $this->em->flush();
        $projectId = (string) $project->id;
        $this->em->clear();

        $this->migrateAgain();
        $this->apply(new Version20260912201847($this->connection, new NullLogger()));

        self::assertSame(self::SEEDED, $this->columns($projectId));
    }

    /** The image that predates the table deletes a project's cards and then the project, and never its columns. */
    public function test_a_project_whose_columns_nobody_deletes_can_still_be_deleted(): void
    {
        $project = new Project($this->owner('board-columns-cascade'), 'cascade-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->persist(new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Ship it', body: 'Body', number: 1));
        $this->em->flush();
        $projectId = (string) $project->id;
        $this->em->clear();

        $this->migrateAgain();
        // Guard: the delete below proves nothing about columns that were never there.
        self::assertCount(4, $this->columns($projectId));

        $this->connection->executeStatement('DELETE FROM board_cards WHERE project_id = :id', ['id' => $projectId]);
        $this->connection->executeStatement('DELETE FROM projects WHERE id = :id', ['id' => $projectId]);

        self::assertSame([], $this->columns($projectId));
    }

    private function owner(string $label): User
    {
        $owner = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);

        return $owner;
    }

    /** @return list<array<string, mixed>> */
    private function columns(string $projectId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT slug, label, position, terminal, is_default FROM board_columns WHERE project_id = :id ORDER BY position',
            ['id' => $projectId],
        );
    }

    private function migrateAgain(): void
    {
        $this->connection->executeStatement('ALTER TABLE board_cards DROP column_id');
        $this->connection->executeStatement('DROP TABLE board_columns');
        $this->apply(new Version20260912201432($this->connection, new NullLogger()));
        $this->apply(new Version20260912201847($this->connection, new NullLogger()));
    }

    private function apply(AbstractMigration $migration): void
    {
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
