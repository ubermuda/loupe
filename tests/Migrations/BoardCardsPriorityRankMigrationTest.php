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
use DoctrineMigrations\Version20260918102619;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260918102619.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class BoardCardsPriorityRankMigrationTest extends KernelTestCase
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

    public function test_up_ranks_an_open_column_in_the_order_it_showed_highest_priority_first(): void
    {
        $project = $this->project();
        $backlog = $this->column($project, 'backlog');
        $low = $this->card($project, $backlog, 1);
        $highSecond = $this->card($project, $backlog, 2);
        $highFirst = $this->card($project, $backlog, 3);
        $medium = $this->card($project, $backlog, 4);
        $this->em->flush();
        $this->migrate(down: true);
        $this->grade($low, 30, 0);
        $this->grade($highSecond, 10, 1);
        $this->grade($highFirst, 10, 0);
        $this->grade($medium, 20, 0);

        $this->migrate();

        self::assertSame(
            [(string) $highFirst->id => 0, (string) $highSecond->id => 1, (string) $medium->id => 2, (string) $low->id => 3],
            $this->positions($backlog),
        );
    }

    public function test_up_leaves_a_terminal_column_unranked(): void
    {
        $project = $this->project();
        $done = $this->column($project, 'done');
        $first = $this->card($project, $done, 1);
        $second = $this->card($project, $done, 2);
        $this->em->flush();
        $this->migrate(down: true);
        $this->grade($first, 10, 0);
        $this->grade($second, 30, 0);

        $this->migrate();

        self::assertSame([(string) $first->id => 0, (string) $second->id => 0], $this->positions($done));
    }

    public function test_up_fills_priority_for_an_insert_that_omits_it_so_the_previous_image_still_reads_the_row(): void
    {
        $project = $this->project();
        $card = $this->card($project, $this->column($project, 'backlog'), 1);
        $this->em->flush();

        self::assertSame(20, (int) $this->connection->fetchOne('SELECT priority FROM board_cards WHERE id = :id', ['id' => (string) $card->id]));
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'board-priority-drop-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'priority-drop-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);

        return $project;
    }

    private function card(Project $project, BoardColumn $column, int $number): Card
    {
        $card = new Card(
            project: $project,
            column: $column,
            title: 'Card '.$number,
            body: '',
            number: $number,
            createdAt: new \DateTimeImmutable(\sprintf('2026-09-01 10:00:%02d', $number)),
        );
        $this->em->persist($card);

        return $card;
    }

    private function grade(Card $card, int $priority, int $position): void
    {
        $this->connection->executeStatement(
            'UPDATE board_cards SET priority = :priority, position = :position WHERE id = :id',
            ['priority' => $priority, 'position' => $position, 'id' => (string) $card->id],
        );
    }

    /** @return array<string, int> card id => position, in position order */
    private function positions(BoardColumn $column): array
    {
        return array_map(intval(...), $this->connection->fetchAllKeyValue(
            'SELECT id, position FROM board_cards WHERE column_id = :column ORDER BY position, number',
            ['column' => (string) $column->id],
        ));
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20260918102619($this->connection, new NullLogger());
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
