<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Mercure\LiveUpdates;
use App\Outbox\AgentPush;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260913164721;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260913164721.php';

final class LiveUpdatesFlagSeedMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_an_instance_without_the_flag_gets_it_with_the_agent_push_value(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [LiveUpdates::FLAG]);
        self::assertSame(1, $this->connection->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]));

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'false']], $this->liveUpdatesRows());
    }

    public function test_an_instance_without_either_flag_gets_it_on(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name IN (?, ?)', [LiveUpdates::FLAG, AgentPush::FLAG]);

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'true']], $this->liveUpdatesRows());
    }

    public function test_an_existing_row_is_left_alone(): void
    {
        self::assertSame(1, $this->connection->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [LiveUpdates::FLAG]));
        $this->connection->executeStatement("UPDATE feature_flag SET value = 'true' WHERE name = ?", [AgentPush::FLAG]);

        $this->migrate();

        self::assertSame([['type' => 'bool', 'value' => 'false']], $this->liveUpdatesRows());
    }

    private function migrate(): void
    {
        $migration = new Version20260913164721($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function liveUpdatesRows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, value::text AS value FROM feature_flag WHERE name = ?', [LiveUpdates::FLAG]);
    }
}
