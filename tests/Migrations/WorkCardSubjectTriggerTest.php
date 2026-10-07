<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The previous image writes card_id and no subject during a deploy or after a
 * rollback, and reads card_id. The trigger fills each side from the other.
 */
final class WorkCardSubjectTriggerTest extends KernelTestCase
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
        $id = Uuid::v7()->toRfc4122();

        $connection->insert($table, ['id' => $id, 'project_id' => $projectId, 'subject_type' => 'analysis', 'subject_id' => Uuid::v7()->toRfc4122()] + array_diff_key($columns, ['card_number' => true]));

        self::assertNull($connection->fetchOne("SELECT card_id FROM {$table} WHERE id = ?", [$id]));
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
