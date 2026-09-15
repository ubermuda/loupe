<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Inbox\Install\InboxInstallFlags;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260914151507;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260914151507.php';

final class InboxFlagSeedMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_an_instance_without_the_flag_gets_it_off(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [InboxInstallFlags::FLAG_INBOX_ENABLED]);

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'false']], $this->rows());
    }

    public function test_an_existing_row_is_left_alone(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [InboxInstallFlags::FLAG_INBOX_ENABLED]);
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'bool', 'true', '[]', NULL)", [InboxInstallFlags::FLAG_INBOX_ENABLED]);

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'true']], $this->rows());
    }

    private function migrate(): void
    {
        $migration = new Version20260914151507($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, value::text AS value FROM feature_flag WHERE name = ?', [InboxInstallFlags::FLAG_INBOX_ENABLED]);
    }
}
