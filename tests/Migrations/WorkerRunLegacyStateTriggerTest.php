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

/** The previous image inserts runs with no state, as it does during a deploy or after a rollback. */
final class WorkerRunLegacyStateTriggerTest extends KernelTestCase
{
    /** @return iterable<string, array{?int, ?string, string}> */
    public static function legacyRows(): iterable
    {
        yield 'exit code 0' => [0, null, 'succeeded'];
        yield 'exit code 1' => [1, null, 'failed'];
        yield 'never started' => [null, 'spawn failed', 'not-started'];
    }

    #[DataProvider('legacyRows')]
    public function test_a_row_with_no_state_takes_its_state_from_the_exit_code(?int $exitCode, ?string $failureReason, string $expected): void
    {
        [$connection, $projectId] = $this->projectConnection();

        $id = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            'INSERT INTO bridge_worker_runs (id, project_id, bridge_id, session_id, card_id, card_number, rule_name, started_at, ended_at, exit_code, failure_reason, output, received_at)
             VALUES (:id, :project, :bridge, :session, :card, 3, :rule, NOW(), NOW(), :exit, :reason, :output, NOW())',
            [
                'id' => $id,
                'project' => $projectId,
                'bridge' => Uuid::v7()->toRfc4122(),
                'session' => Uuid::v4()->toRfc4122(),
                'card' => Uuid::v7()->toRfc4122(),
                'rule' => 'plan',
                'exit' => $exitCode,
                'reason' => $failureReason,
                'output' => '',
            ],
        );

        self::assertSame($expected, $connection->fetchOne('SELECT state FROM bridge_worker_runs WHERE id = ?', [$id]));
    }

    public function test_a_row_with_a_run_key_keeps_the_state_it_names(): void
    {
        [$connection, $projectId] = $this->projectConnection();

        $id = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            "INSERT INTO bridge_worker_runs (id, project_id, bridge_id, run_key, state, card_id, card_number, rule_name, output, received_at)
             VALUES (:id, :project, :bridge, :runKey, 'failed', :card, 3, 'plan', '', NOW())",
            [
                'id' => $id,
                'project' => $projectId,
                'bridge' => Uuid::v7()->toRfc4122(),
                'runKey' => Uuid::v4()->toRfc4122(),
                'card' => Uuid::v7()->toRfc4122(),
            ],
        );

        self::assertSame('failed', $connection->fetchOne('SELECT state FROM bridge_worker_runs WHERE id = ?', [$id]));
    }

    /** @return array{Connection, string} */
    private function projectConnection(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Legacy', email: 'legacy-state-'.bin2hex(random_bytes(4)).'@example.com');
        $project = new Project($owner, 'legacy-state');
        $em->persist($owner);
        $em->persist($project);
        $em->flush();

        return [$em->getConnection(), (string) $project->id];
    }
}
