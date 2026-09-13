<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260912234634;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260912234634.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class BoardCardsStatusNullableMigrationTest extends KernelTestCase
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

    public function test_a_card_this_image_writes_leaves_status_null(): void
    {
        $cardId = $this->cardInColumn('backlog');

        self::assertNull($this->storedStatus($cardId));
        self::assertSame('YES', $this->statusNullable());
        self::assertFalse($this->oldIndexExists());
    }

    public function test_down_fills_status_from_the_column_and_restores_the_index(): void
    {
        $cardId = $this->cardInColumn('waiting-for-the-customer-to-answer');
        // A card this image moved keeps the slug an older image wrote.
        $this->connection->executeStatement("UPDATE board_cards SET status = 'backlog' WHERE id = :id", ['id' => $cardId]);

        $this->migrate(down: true);

        self::assertSame('waiting-for-the-cust', $this->storedStatus($cardId));
        self::assertSame('NO', $this->statusNullable());
        self::assertTrue($this->oldIndexExists());

        $this->migrate();

        self::assertSame('YES', $this->statusNullable());
        self::assertFalse($this->oldIndexExists());
    }

    private function cardInColumn(string $slug): string
    {
        $owner = new User(fullName: 'Riley', email: 'board-status-nullable-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'status-nullable-'.uniqid());
        $this->em->persist($project);
        $column = new BoardColumn(project: $project, label: $slug, slug: $slug, position: 0, terminal: false);
        $this->em->persist($column);
        $card = new Card(project: $project, column: $column, title: 'Ship it', body: 'Body', number: 1);
        $this->em->persist($card);
        $this->em->flush();
        $this->em->clear();

        return (string) $card->id;
    }

    private function storedStatus(string $cardId): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM board_cards WHERE id = :id', ['id' => $cardId]);
    }

    private function statusNullable(): mixed
    {
        return $this->connection->fetchOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'board_cards' AND column_name = 'status'");
    }

    private function oldIndexExists(): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM pg_indexes WHERE tablename = 'board_cards' AND indexname = 'idx_board_cards_board_order'");
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20260912234634($this->connection, new NullLogger());
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
