<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WorkerRunToolCall>
 */
class WorkerRunToolCallRepository extends ServiceEntityRepository
{
    private const string INSERT_SQL = <<<'SQL'
        INSERT INTO bridge_worker_run_tool_calls (
            id, run_id, seq, tool, started_at, duration_ms, is_error, in_subagent,
            background_id, waits_on, signatures, full_text
        )
        SELECT
            c.id, c.run_id, c.seq, c.tool, c.started_at, c.duration_ms, c.is_error, c.in_subagent,
            c.background_id, c.waits_on, c.signatures, c.full_text
        FROM jsonb_to_recordset(CAST(:calls AS jsonb)) AS c(
            id uuid, run_id uuid, seq int, tool text, started_at timestamp, duration_ms bigint, is_error boolean,
            in_subagent boolean, background_id text, waits_on text, signatures json, full_text text
        )
        ON CONFLICT (run_id, seq) DO NOTHING
        SQL;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunToolCall::class);
    }

    /**
     * Inserts the calls in one statement. A call whose seq the run already
     * holds, or that the batch repeats, is skipped.
     *
     * @param list<WorkerRunToolCallReport> $calls
     *
     * @return int the count of rows inserted
     */
    public function insertNew(WorkerRun $run, array $calls): int
    {
        if ([] === $calls) {
            return 0;
        }

        $runId = (string) ($run->id ?? throw new \LogicException('A reported run has an id.'));
        $rows = array_map(static fn (WorkerRunToolCallReport $call): array => [
            'id' => Uuid::v7()->toRfc4122(),
            'run_id' => $runId,
            'seq' => $call->seq,
            'tool' => $call->tool,
            'started_at' => $call->startedAt->format('Y-m-d H:i:s.u'),
            'duration_ms' => $call->durationMs,
            'is_error' => $call->isError,
            'in_subagent' => $call->inSubagent,
            'background_id' => $call->backgroundId,
            'waits_on' => $call->waitsOn,
            'signatures' => $call->signatures,
            'full_text' => $call->fullText,
        ], $calls);

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            self::INSERT_SQL,
            ['calls' => json_encode($rows, \JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * What the bucket computation reads of every call of the run, in order.
     *
     * @return list<array{signatures: list<string>, startedAt: \DateTimeImmutable, durationMs: ?int, inSubagent: bool}>
     */
    public function findBucketInputsOfRun(Uuid $runId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT signatures, started_at, duration_ms, in_subagent FROM bridge_worker_run_tool_calls WHERE run_id = :run ORDER BY seq',
            ['run' => $runId->toRfc4122()],
        );

        return array_map(static fn (array $row): array => [
            'signatures' => array_values(array_map(strval(...), json_decode((string) $row['signatures'], true, 512, \JSON_THROW_ON_ERROR))),
            'startedAt' => new \DateTimeImmutable((string) $row['started_at'], new \DateTimeZone('UTC')),
            'durationMs' => null === $row['duration_ms'] ? null : (int) $row['duration_ms'],
            'inSubagent' => (bool) $row['in_subagent'],
        ], $rows);
    }

    /**
     * The ids of the project runs that still hold a tool call, in id order,
     * after the given id.
     *
     * @return list<Uuid>
     */
    public function findRunIdsWithCallsOfProject(Project $project, ?Uuid $after, int $limit): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT r.id FROM bridge_worker_runs r WHERE r.project_id = :project AND (CAST(:after AS uuid) IS NULL OR r.id > CAST(:after AS uuid)) AND EXISTS (SELECT 1 FROM bridge_worker_run_tool_calls c WHERE c.run_id = r.id) ORDER BY r.id LIMIT '.$limit,
            ['project' => (string) $project->id, 'after' => $after?->toRfc4122()],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromString((string) $id), $ids);
    }

    /** @return Paginator<WorkerRunToolCall> */
    public function findPageOfRun(WorkerRun $run, int $page, int $perPage): Paginator
    {
        return new Paginator($this->createQueryBuilder('c')
            ->andWhere('c.run = :run')
            ->setParameter('run', $run)
            ->orderBy('c.seq', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery(), fetchJoinCollection: false);
    }

    /**
     * Every tool call of every run on every project the user owns, for the
     * account data export.
     *
     * @return list<WorkerRunToolCall>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('c')
            ->join('c.run', 'r')
            ->join('r.project', 'p')
            ->addSelect('r', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('r.id', 'ASC')
            ->addOrderBy('c.seq', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
