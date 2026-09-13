<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260912235455;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260912235455.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class BoardCardsStatusDropMigrationTest extends KernelTestCase
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

    public function test_down_restores_a_nullable_status_filled_from_the_column_and_a_nullable_column_id(): void
    {
        $project = $this->project();
        $column = new BoardColumn(project: $project, label: 'Waiting for the customer to answer', slug: 'waiting-for-the-customer-to-answer', position: 4, terminal: false);
        $this->em->persist($column);
        $cardId = $this->card($project, $column);

        $this->migrate(down: true);

        self::assertSame('waiting-for-the-cust', $this->connection->fetchOne('SELECT status FROM board_cards WHERE id = :id', ['id' => $cardId]));
        self::assertSame(['status' => 'YES', 'column_id' => 'YES'], $this->nullability());
    }

    public function test_up_gives_a_card_with_no_column_the_column_its_status_names_and_then_requires_one(): void
    {
        $project = $this->project();
        $cardId = $this->card($project, $this->column($project, 'backlog'));
        $this->migrate(down: true);
        $this->connection->executeStatement("UPDATE board_cards SET column_id = NULL, status = 'next' WHERE id = :id", ['id' => $cardId]);
        // Guard: the row starts with no column, so the backfill below has work to do.
        self::assertNull($this->connection->fetchOne('SELECT column_id FROM board_cards WHERE id = :id', ['id' => $cardId]));

        $this->migrate();

        self::assertSame('next', $this->connection->fetchOne(
            'SELECT k.slug FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = :id',
            ['id' => $cardId],
        ));
        self::assertSame(['column_id' => 'NO'], $this->nullability());
    }

    public function test_up_puts_a_card_whose_status_names_a_renamed_slug_in_the_default_column(): void
    {
        $project = $this->project();
        $cardId = $this->card($project, $this->column($project, 'backlog'));
        $projectId = (string) $project->id;
        $this->migrate(down: true);
        $this->connection->executeStatement("UPDATE board_cards SET column_id = NULL, status = 'in-progress' WHERE id = :id", ['id' => $cardId]);
        $this->connection->executeStatement("UPDATE board_columns SET slug = 'doing' WHERE project_id = :id AND slug = 'in-progress'", ['id' => $projectId]);
        // The default moves off the first column, so the test cannot pass on "first by position".
        $this->connection->executeStatement("UPDATE board_columns SET is_default = (slug = 'next') WHERE project_id = :id", ['id' => $projectId]);

        $this->migrate();

        self::assertSame('next', $this->connection->fetchOne(
            'SELECT k.slug FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = :id',
            ['id' => $cardId],
        ));
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'board-status-drop-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'status-drop-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);

        return $project;
    }

    private function card(Project $project, BoardColumn $column): string
    {
        $card = new Card(project: $project, column: $column, title: 'Ship it', body: 'Body', number: 1);
        $this->em->persist($card);
        $this->em->flush();
        $this->em->clear();

        return (string) $card->id;
    }

    /** @return array<string, string> column name => is_nullable, for the two columns this migration changes */
    private function nullability(): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            "SELECT column_name, is_nullable FROM information_schema.columns
             WHERE table_name = 'board_cards' AND column_name IN ('status', 'column_id') ORDER BY column_name DESC",
        );

        return $rows;
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20260912235455($this->connection, new NullLogger());
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
