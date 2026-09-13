<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260912202421;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

// Migrations are not autoloaded (see config/packages/doctrine_migrations.yaml).
require_once __DIR__.'/../../migrations/Version20260912202421.php';

final class ProjectSlugBackfillTest extends KernelTestCase
{
    public function test_each_project_takes_the_slug_of_its_name(): void
    {
        self::assertSame(
            ['a' => 'in-progress', 'b' => 'cafe', 'c' => 'wan-le'],
            Version20260912202421::assignSlugs([
                ['id' => 'a', 'owner_id' => 'o', 'name' => 'In progress'],
                ['id' => 'b', 'owner_id' => 'o', 'name' => 'Café'],
                ['id' => 'c', 'owner_id' => 'o', 'name' => '完了'],
            ]),
        );
    }

    public function test_a_later_colliding_project_takes_the_first_free_suffix(): void
    {
        self::assertSame(
            ['older' => 'my-app', 'named-2' => 'my-app-2', 'later' => 'my-app-3'],
            Version20260912202421::assignSlugs([
                ['id' => 'older', 'owner_id' => 'o', 'name' => 'My App'],
                ['id' => 'named-2', 'owner_id' => 'o', 'name' => 'my-app-2'],
                ['id' => 'later', 'owner_id' => 'o', 'name' => 'my-app'],
            ]),
        );
    }

    public function test_a_name_with_no_slug_takes_the_fallback_under_the_same_rule(): void
    {
        self::assertSame(
            ['plain' => 'project', 'rocket' => 'project-2', 'moon' => 'project-3'],
            Version20260912202421::assignSlugs([
                ['id' => 'plain', 'owner_id' => 'o', 'name' => 'Project'],
                ['id' => 'rocket', 'owner_id' => 'o', 'name' => '🚀'],
                ['id' => 'moon', 'owner_id' => 'o', 'name' => '🌙'],
            ]),
        );
    }

    public function test_two_owners_never_share_a_suffix_count(): void
    {
        self::assertSame(
            ['mine' => 'my-app', 'theirs' => 'my-app'],
            Version20260912202421::assignSlugs([
                ['id' => 'mine', 'owner_id' => 'o1', 'name' => 'My App'],
                ['id' => 'theirs', 'owner_id' => 'o2', 'name' => 'my-app'],
            ]),
        );
    }

    public function test_the_older_project_by_creation_keeps_the_plain_slug(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();

        $owner = new User(fullName: 'U', email: 'slug-backfill@example.com', password: 'x');
        $em->persist($owner);
        $em->flush();

        // Written through the connection with no slug, as a row the previous image
        // wrote. The newer row goes in first, so row order cannot pass for date order.
        $newer = $this->insertProject($connection, (string) $owner->id, 'my-app', '2026-02-01 00:00:00');
        $older = $this->insertProject($connection, (string) $owner->id, 'My App', '2026-01-01 00:00:00');

        $updates = $this->backfillUpdates($connection);

        self::assertSame('my-app', $updates[$older] ?? null);
        self::assertSame('my-app-2', $updates[$newer] ?? null);
    }

    private function insertProject(Connection $connection, string $ownerId, string $name, string $createdAt): string
    {
        $id = (string) Uuid::v7();
        $connection->insert('projects', [
            'id' => $id,
            'owner_id' => $ownerId,
            'name' => $name,
            'created_at' => $createdAt,
        ]);

        return $id;
    }

    /** @return array<string, string> the slug each queued UPDATE writes, by project id */
    private function backfillUpdates(Connection $connection): array
    {
        $migration = new Version20260912202421($connection, new NullLogger());
        $migration->up(new Schema());

        $updates = [];
        foreach ($migration->getSql() as $query) {
            $parameters = $query->getParameters();
            if (str_starts_with($query->getStatement(), 'UPDATE projects')) {
                $updates[(string) $parameters['id']] = (string) $parameters['slug'];
            }
        }

        return $updates;
    }
}
