<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261006022037;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../migrations/Version20261006022037.php';

/**
 * Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL.
 * After down(), the trigger fills card_id and the subject from each other for the previous image.
 */
final class WorkCardIdDropMigrationTest extends KernelTestCase
{
    /** @return iterable<string, array{string, array<string, string|int>}> */
    public static function tables(): iterable
    {
        yield 'work request' => ['work_requests', ['card_number' => 7, 'kind' => 'implement', 'rule_id' => 'implement-on-entry', 'state' => 'open', 'created_at' => '2026-10-01 12:00:00']];
        yield 'worker run' => ['bridge_worker_runs', ['card_number' => 7, 'output' => '', 'received_at' => '2026-10-01 12:00:00']];
        yield 'run usage' => ['bridge_worker_run_usage', ['model' => 'claude-haiku', 'source' => 'reported', 'input_tokens' => 1, 'output_tokens' => 2, 'cache_read_tokens' => 3, 'cache_write_tokens' => 4]];
    }

    /** @param array<string, string|int> $columns */
    #[DataProvider('tables')]
    public function test_a_row_with_a_card_and_no_subject_takes_the_card_as_its_subject(string $table, array $columns): void
    {
        [$connection, $projectId] = $this->projectConnection();
        $this->migrate($connection, down: true);
        $id = Uuid::v7()->toRfc4122();
        $cardId = Uuid::v7()->toRfc4122();

        $connection->insert($table, ['id' => $id, 'project_id' => $projectId, 'card_id' => $cardId] + $columns);

        self::assertSame(
            ['subject_type' => 'card', 'subject_id' => $cardId, 'card_id' => $cardId],
            $connection->fetchAssociative("SELECT subject_type, subject_id, card_id FROM {$table} WHERE id = ?", [$id]),
        );
    }

    /** @param array<string, string|int> $columns */
    #[DataProvider('tables')]
    public function test_a_row_about_a_card_with_no_card_id_gets_the_card_id_for_the_previous_image(string $table, array $columns): void
    {
        [$connection, $projectId] = $this->projectConnection();
        $this->migrate($connection, down: true);
        $id = Uuid::v7()->toRfc4122();
        $cardId = Uuid::v7()->toRfc4122();

        $connection->insert($table, ['id' => $id, 'project_id' => $projectId, 'subject_type' => 'card', 'subject_id' => $cardId] + $columns);

        self::assertSame($cardId, $connection->fetchOne("SELECT card_id FROM {$table} WHERE id = ?", [$id]));
    }

    /** @param array<string, string|int> $columns */
    #[DataProvider('tables')]
    public function test_a_row_about_another_subject_has_no_card_id(string $table, array $columns): void
    {
        [$connection, $projectId] = $this->projectConnection();
        $this->migrate($connection, down: true);
        $id = Uuid::v7()->toRfc4122();

        $connection->insert($table, ['id' => $id, 'project_id' => $projectId, 'subject_type' => 'analysis', 'subject_id' => Uuid::v7()->toRfc4122()] + array_diff_key($columns, ['card_number' => true]));

        self::assertNull($connection->fetchOne("SELECT card_id FROM {$table} WHERE id = ?", [$id]));
    }

    public function test_up_drops_card_id_and_the_trigger_from_every_table(): void
    {
        [$connection] = $this->projectConnection();
        $this->migrate($connection, down: true);

        $this->migrate($connection);

        self::assertSame(0, (int) $connection->fetchOne(
            "SELECT count(*) FROM information_schema.columns WHERE column_name = 'card_id' AND table_name IN ('work_requests', 'bridge_worker_runs', 'bridge_worker_run_usage')",
        ));
        self::assertSame(0, (int) $connection->fetchOne("SELECT count(*) FROM pg_proc WHERE proname = 'work_card_subject'"));
        self::assertSame(0, (int) $connection->fetchOne("SELECT count(*) FROM pg_trigger WHERE tgname LIKE '%_card_subject'"));
    }

    /** @param array<string, string|int> $columns */
    #[DataProvider('tables')]
    public function test_down_fills_card_id_from_a_card_subject_only(string $table, array $columns): void
    {
        [$connection, $projectId] = $this->projectConnection();
        $cardRow = Uuid::v7()->toRfc4122();
        $cardId = Uuid::v7()->toRfc4122();
        $otherRow = Uuid::v7()->toRfc4122();
        $connection->insert($table, ['id' => $cardRow, 'project_id' => $projectId, 'subject_type' => 'card', 'subject_id' => $cardId] + $columns);
        $connection->insert($table, ['id' => $otherRow, 'project_id' => $projectId, 'subject_type' => 'analysis', 'subject_id' => Uuid::v7()->toRfc4122()] + array_diff_key($columns, ['card_number' => true]));

        $this->migrate($connection, down: true);

        self::assertSame($cardId, $connection->fetchOne("SELECT card_id FROM {$table} WHERE id = ?", [$cardRow]));
        self::assertNull($connection->fetchOne("SELECT card_id FROM {$table} WHERE id = ?", [$otherRow]));
    }

    private function migrate(Connection $connection, bool $down = false): void
    {
        $migration = new Version20261006022037($connection, new NullLogger());
        if ($down) {
            $migration->down(new Schema());
        } else {
            $migration->up(new Schema());
        }
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array{Connection, string} */
    private function projectConnection(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Subject', email: 'card-subject-'.bin2hex(random_bytes(4)).'@example.com');
        $project = new Project($owner, 'card-subject');
        $em->persist($owner);
        $em->persist($project);
        $em->flush();

        return [$em->getConnection(), (string) $project->id];
    }
}
