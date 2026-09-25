<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Install\BoardInstallFlags;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260924222427;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260924222427.php';

final class BoardFlagEnableMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_an_instance_with_the_board_off_turns_it_on(): void
    {
        self::assertSame(1, $this->connection->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [BoardInstallFlags::FLAG_BOARD_ENABLED]));

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'true']], $this->boardRows());
    }

    public function test_a_row_with_a_null_value_turns_on(): void
    {
        self::assertSame(1, $this->connection->executeStatement('UPDATE feature_flag SET value = NULL WHERE name = ?', [BoardInstallFlags::FLAG_BOARD_ENABLED]));

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'true']], $this->boardRows());
    }

    public function test_an_instance_without_the_flag_gets_it_on(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [BoardInstallFlags::FLAG_BOARD_ENABLED]);

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'true']], $this->boardRows());
    }

    public function test_an_instance_with_the_board_on_keeps_one_row(): void
    {
        self::assertSame(1, $this->connection->executeStatement("UPDATE feature_flag SET value = 'true' WHERE name = ?", [BoardInstallFlags::FLAG_BOARD_ENABLED]));

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'true']], $this->boardRows());
    }

    private function migrate(): void
    {
        $migration = new Version20260924222427($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function boardRows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, value::text AS value FROM feature_flag WHERE name = ?', [BoardInstallFlags::FLAG_BOARD_ENABLED]);
    }
}
