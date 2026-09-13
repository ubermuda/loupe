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
use DoctrineMigrations\Version20260912210932;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260912210932.php';

/**
 * The image before columns keeps writing while the first migration runs. It
 * creates projects with no columns and moves cards without their column_id,
 * so the second migration repairs both.
 */
final class BoardColumnsReseedMigrationTest extends KernelTestCase
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

    public function test_a_project_without_columns_is_seeded_and_every_card_follows_its_status(): void
    {
        $owner = new User(fullName: 'Riley', email: 'board-columns-reseed-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $seeded = $this->projectWithCard($owner, 'seeded');
        $bare = $this->projectWithCard($owner, 'bare');
        $this->em->flush();
        $seededId = (string) $seeded->id;
        $bareId = (string) $bare->id;
        $this->em->clear();

        // A move the old image made: status changed, column_id did not.
        $this->connection->executeStatement("UPDATE board_cards SET status = 'done' WHERE project_id = :id", ['id' => $seededId]);
        // A project the old image created: no columns, and cards with no column_id.
        $this->connection->executeStatement("UPDATE board_cards SET column_id = NULL, status = 'next' WHERE project_id = :id", ['id' => $bareId]);
        $this->connection->executeStatement('DELETE FROM board_columns WHERE project_id = :id', ['id' => $bareId]);
        $this->connection->executeStatement('DROP INDEX idx_board_cards_column_order');

        $this->runMigration();

        self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_columns WHERE project_id = :id', ['id' => $bareId]));
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_columns WHERE project_id = :id', ['id' => $seededId]));
        self::assertSame('done', $this->columnSlugOfTheCardIn($seededId));
        self::assertSame('next', $this->columnSlugOfTheCardIn($bareId));
    }

    private function projectWithCard(User $owner, string $name): Project
    {
        $project = new Project($owner, $name.'-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->persist(new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Ship it', body: 'Body', number: 1));

        return $project;
    }

    private function columnSlugOfTheCardIn(string $projectId): ?string
    {
        $slug = $this->connection->fetchOne(
            'SELECT k.slug FROM board_cards c LEFT JOIN board_columns k ON k.id = c.column_id AND k.project_id = c.project_id WHERE c.project_id = :id',
            ['id' => $projectId],
        );
        self::assertNotFalse($slug);

        return null === $slug ? null : (string) $slug;
    }

    private function runMigration(): void
    {
        $migration = new Version20260912210932($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
