<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardStatus;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260912201432;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260912201432.php';

/**
 * Runs the migration against rows written before it, inside the test's own
 * transaction. Postgres rolls DDL back with the rest, so dropping what the
 * bootstrap migrated and running up() again leaves nothing behind.
 */
final class BoardColumnsSeedMigrationTest extends KernelTestCase
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

    public function test_every_project_gets_four_columns_and_every_card_its_matching_column(): void
    {
        $owner = new User(fullName: 'Riley', email: 'board-columns-migration-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);

        $projectIds = [];
        foreach (['first', 'second'] as $name) {
            $project = new Project($owner, $name.'-'.uniqid());
            $this->em->persist($project);
            foreach (CardStatus::cases() as $number => $status) {
                $this->em->persist(new Card(project: $project, title: $status->value, body: 'Body', number: $number + 1, status: $status));
            }
            $this->em->flush();
            $projectIds[] = (string) $project->id;
        }
        $this->em->clear();

        $this->connection->executeStatement('ALTER TABLE board_cards DROP column_id');
        $this->connection->executeStatement('DROP TABLE board_columns');
        $this->runMigration();

        foreach ($projectIds as $projectId) {
            self::assertSame(
                [
                    ['slug' => 'backlog', 'label' => 'board.card.status.backlog', 'position' => 0, 'terminal' => false, 'is_default' => true],
                    ['slug' => 'next', 'label' => 'board.card.status.next', 'position' => 1, 'terminal' => false, 'is_default' => false],
                    ['slug' => 'in-progress', 'label' => 'board.card.status.in-progress', 'position' => 2, 'terminal' => false, 'is_default' => false],
                    ['slug' => 'done', 'label' => 'board.card.status.done', 'position' => 3, 'terminal' => true, 'is_default' => false],
                ],
                $this->connection->fetchAllAssociative(
                    'SELECT slug, label, position, terminal, is_default FROM board_columns WHERE project_id = :id ORDER BY position',
                    ['id' => $projectId],
                ),
            );

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

    private function runMigration(): void
    {
        $migration = new Version20260912201432($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
