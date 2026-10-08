<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\Service\HostSampling;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261007191556;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261007191556.php';

final class HostSampleIntervalFlagSeedMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_an_instance_without_the_flag_gets_sixty_seconds(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [HostSampling::INTERVAL_FLAG]);

        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '60']], $this->rows());
    }

    public function test_an_existing_row_is_left_alone(): void
    {
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [HostSampling::INTERVAL_FLAG]);
        $this->connection->executeStatement("INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'int', '90', '[]', NULL)", [HostSampling::INTERVAL_FLAG]);

        $this->migrate();

        self::assertSame([['type' => 'int', 'value' => '90']], $this->rows());
    }

    private function migrate(): void
    {
        $migration = new Version20261007191556($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, value #>> \'{}\' AS value FROM feature_flag WHERE name = ?', [HostSampling::INTERVAL_FLAG]);
    }
}
