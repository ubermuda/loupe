<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Entity\WorkflowBinding;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261008001500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261008001500.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class BoardCardsSourceMigrationTest extends KernelTestCase
{
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

    public function test_up_fills_the_source_from_the_reporter_and_retires_the_site_review_type(): void
    {
        $owner = new User(fullName: 'Riley', email: 'board-source-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'board-source-'.uniqid());
        $this->em->persist($project);
        $column = new BoardColumn(project: $project, label: 'Backlog', slug: 'backlog', position: 0, terminal: false);
        $this->em->persist($column);

        $ids = [];
        foreach (['human', 'agent', 'reviewer', 'system', 'orphan'] as $number => $reporter) {
            $card = new Card(project: $project, column: $column, title: 'Card '.$reporter, body: '', number: $number + 1);
            $this->em->persist($card);
            $ids[$reporter] = $card;
        }
        $this->em->flush();
        $this->em->clear();

        $this->migrate(down: true);
        self::assertSame(0, $this->sourceColumnCount());

        foreach (['human', 'agent', 'reviewer', 'system'] as $reporter) {
            $this->connection->executeStatement('UPDATE board_cards SET reporter = :reporter, origin = :other WHERE id = :id', [
                'reporter' => $reporter,
                'other' => 'agent',
                'id' => (string) $ids[$reporter]->id,
            ]);
        }
        // A row with no reporter reads its origin.
        $this->connection->executeStatement("UPDATE board_cards SET reporter = NULL, origin = 'reviewer', type = 'site-review' WHERE id = :id", ['id' => (string) $ids['orphan']->id]);

        $this->migrate();

        $expected = ['human' => 'person', 'agent' => 'agent', 'reviewer' => 'widget', 'system' => 'loupe', 'orphan' => 'widget'];
        foreach ($expected as $reporter => $source) {
            self::assertSame($source, $this->connection->fetchOne('SELECT source FROM board_cards WHERE id = :id', ['id' => (string) $ids[$reporter]->id]), $reporter);
        }
        self::assertSame('feature', $this->connection->fetchOne('SELECT type FROM board_cards WHERE id = :id', ['id' => (string) $ids['orphan']->id]));
        self::assertSame(3, $this->sourceColumnCount());
    }

    public function test_up_gives_a_site_review_card_the_default_type_of_its_project_template(): void
    {
        $owner = new User(fullName: 'Riley', email: 'board-source-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'board-source-'.uniqid());
        $this->em->persist($project);
        $column = new BoardColumn(project: $project, label: 'Backlog', slug: 'backlog', position: 0, terminal: false);
        $this->em->persist($column);
        $card = new Card(project: $project, column: $column, title: 'Old', body: '', number: 1);
        $this->em->persist($card);
        $this->em->persist(new WorkflowBinding($project, 'custom', 1, ['defaultType' => 'story']));
        $this->em->flush();
        $this->em->clear();

        $this->migrate(down: true);
        $this->connection->executeStatement("UPDATE board_cards SET type = 'site-review' WHERE id = :id", ['id' => (string) $card->id]);
        $this->migrate();

        self::assertSame('story', $this->connection->fetchOne('SELECT type FROM board_cards WHERE id = :id', ['id' => (string) $card->id]));
    }

    public function test_up_keeps_the_site_review_type_when_the_project_template_declares_it(): void
    {
        $owner = new User(fullName: 'Riley', email: 'board-source-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'board-source-'.uniqid());
        $this->em->persist($project);
        $column = new BoardColumn(project: $project, label: 'Backlog', slug: 'backlog', position: 0, terminal: false);
        $this->em->persist($column);
        $card = new Card(project: $project, column: $column, title: 'Custom', body: '', number: 1);
        $this->em->persist($card);
        $this->em->persist(new WorkflowBinding($project, 'custom', 1, ['defaultType' => 'story', 'types' => [['key' => 'story'], ['key' => 'site-review']]]));
        $this->em->flush();
        $this->em->clear();

        $this->migrate(down: true);
        $this->connection->executeStatement("UPDATE board_cards SET type = 'site-review' WHERE id = :id", ['id' => (string) $card->id]);
        $this->migrate();

        self::assertSame('site-review', $this->connection->fetchOne('SELECT type FROM board_cards WHERE id = :id', ['id' => (string) $card->id]));
    }

    private function sourceColumnCount(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'board_cards' AND column_name IN ('source', 'source_run_id', 'source_run_card_id')");
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261008001500($this->connection, new NullLogger());
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
