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
use DoctrineMigrations\Version20260928155635;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260928155635.php';

/** Runs up() against rows written the old way, inside the test's own transaction. */
final class BoardColumnsBacklogMigrationTest extends KernelTestCase
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

    public function test_up_gives_a_renamed_default_column_the_backlog_slug_and_label_and_keeps_its_cards(): void
    {
        $projectId = $this->project(cardsIn: ['backlog' => 2]);
        $this->connection->executeStatement(
            "UPDATE board_columns SET slug = 'ideas', label = 'Ideas' WHERE project_id = :id AND slug = 'backlog'",
            ['id' => $projectId],
        );
        $ideasId = $this->columnId($projectId, 'ideas');

        $this->migrate();

        self::assertSame($ideasId, $this->columnId($projectId, 'backlog'));
        self::assertSame(
            [['slug' => 'backlog', 'label' => 'board.card.status.backlog', 'position' => 0, 'terminal' => false, 'is_default' => true]],
            $this->connection->fetchAllAssociative('SELECT slug, label, position, terminal, is_default FROM board_columns WHERE project_id = :id AND is_default', ['id' => $projectId]),
        );
        self::assertSame(['Card backlog 1' => 0, 'Card backlog 2' => 1], $this->positions($ideasId));
    }

    public function test_up_moves_the_cards_of_another_column_named_backlog_to_the_end_of_the_backlog_and_deletes_it(): void
    {
        $projectId = $this->project(cardsIn: ['backlog' => 2, 'next' => 3]);
        // The owner moved the default flag to next, so the old backlog column is an ordinary column.
        $this->connection->executeStatement('UPDATE board_columns SET is_default = (slug = :slug) WHERE project_id = :id', ['slug' => 'next', 'id' => $projectId]);
        $oldBacklogId = $this->columnId($projectId, 'backlog');
        $defaultId = $this->columnId($projectId, 'next');
        // The old column holds its cards in reverse creation order, so the move must follow the rank, not the age.
        $this->connection->executeStatement(
            "UPDATE board_cards SET position = CASE title WHEN 'Card backlog 1' THEN 1 ELSE 0 END WHERE column_id = :id",
            ['id' => $oldBacklogId],
        );

        $this->migrate();

        self::assertSame(
            ['Card next 1' => 0, 'Card next 2' => 1, 'Card next 3' => 2, 'Card backlog 2' => 3, 'Card backlog 1' => 4],
            $this->positions($defaultId),
        );
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM board_columns WHERE id = :id', ['id' => $oldBacklogId]));
        self::assertSame(
            [['slug' => 'backlog', 'position' => 0], ['slug' => 'in-progress', 'position' => 1], ['slug' => 'done', 'position' => 2]],
            $this->connection->fetchAllAssociative('SELECT slug, position FROM board_columns WHERE project_id = :id ORDER BY position', ['id' => $projectId]),
        );
        self::assertSame($defaultId, $this->columnId($projectId, 'backlog'));
    }

    public function test_up_clears_the_completion_of_a_card_moved_from_a_terminal_column_named_backlog(): void
    {
        $projectId = $this->project(cardsIn: ['backlog' => 1]);
        $this->connection->executeStatement('UPDATE board_columns SET is_default = (slug = :slug) WHERE project_id = :id', ['slug' => 'next', 'id' => $projectId]);
        $oldBacklogId = $this->columnId($projectId, 'backlog');
        $this->connection->executeStatement('UPDATE board_columns SET terminal = true WHERE id = :id', ['id' => $oldBacklogId]);
        $this->connection->executeStatement('UPDATE board_cards SET completed_at = NOW() WHERE column_id = :id', ['id' => $oldBacklogId]);

        $this->migrate();

        self::assertSame(
            [['title' => 'Card backlog 1', 'completed_at' => null]],
            $this->connection->fetchAllAssociative('SELECT title, completed_at FROM board_cards WHERE project_id = :id', ['id' => $projectId]),
        );
    }

    public function test_up_puts_the_backlog_first_on_a_board_with_no_cards(): void
    {
        $projectId = $this->project(cardsIn: []);
        $this->connection->executeStatement(
            "UPDATE board_columns SET position = CASE slug WHEN 'backlog' THEN 2 WHEN 'next' THEN 0 WHEN 'in-progress' THEN 1 ELSE 3 END WHERE project_id = :id",
            ['id' => $projectId],
        );

        $this->migrate();

        self::assertSame(
            [
                ['slug' => 'backlog', 'position' => 0, 'is_default' => true],
                ['slug' => 'next', 'position' => 1, 'is_default' => false],
                ['slug' => 'in-progress', 'position' => 2, 'is_default' => false],
                ['slug' => 'done', 'position' => 3, 'is_default' => false],
            ],
            $this->connection->fetchAllAssociative('SELECT slug, position, is_default FROM board_columns WHERE project_id = :id ORDER BY position', ['id' => $projectId]),
        );
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $projectId]));
    }

    /** @param array<string, int> $cardsIn slug => how many cards that column holds, in rank order */
    private function project(array $cardsIn): string
    {
        $owner = new User(fullName: 'Riley', email: 'board-backlog-migration-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'backlog-migration-'.uniqid());
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

    private function columnId(string $projectId, string $slug): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM board_columns WHERE project_id = :id AND slug = :slug', ['id' => $projectId, 'slug' => $slug]);
        self::assertIsString($id);

        return $id;
    }

    /** @return array<string, int> card title => position, in rank order */
    private function positions(string $columnId): array
    {
        /** @var array<string, int> $rows */
        $rows = $this->connection->fetchAllKeyValue('SELECT title, position FROM board_cards WHERE column_id = :id ORDER BY position', ['id' => $columnId]);

        return $rows;
    }

    private function migrate(): void
    {
        $migration = new Version20260928155635($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
