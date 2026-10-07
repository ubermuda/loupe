<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WorkerRunFact>
 */
class WorkerRunFactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunFact::class);
    }

    /**
     * Every fact row on every project the user owns, for the account data
     * export. It includes the rows whose run the retention sweep deleted.
     *
     * @return list<WorkerRunFact>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('f')
            ->join('f.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('f.runId', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The closed runs of the project that ended, or arrived when they have no
     * end, on or after the start.
     *
     * @return list<WorkerRunFact>
     */
    public function findClosedSince(Project $project, ?\DateTimeImmutable $from, bool $cardsOnly): array
    {
        $query = $this->createQueryBuilder('f')
            ->andWhere('f.project = :project')
            ->andWhere('f.outcome NOT IN (:open)')
            ->setParameter('project', $project)
            ->setParameter('open', array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::openStates()));
        if (null !== $from) {
            $query->andWhere('COALESCE(f.endedAt, f.receivedAt) >= :from')->setParameter('from', $from);
        }
        if ($cardsOnly) {
            $query->andWhere("f.subjectType = 'card'");
        }

        return $this->detached($project, $query);
    }

    /**
     * Every run of these cards of the project, open runs included.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<WorkerRunFact>
     */
    public function findOfCards(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        return $this->detached($project, $this->createQueryBuilder('f')
            ->andWhere('f.project = :project')
            ->andWhere("f.subjectType = 'card'")
            ->andWhere('f.subjectId IN (:cards)')
            ->setParameter('project', $project)
            ->setParameter('cards', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds)));
    }

    /**
     * @param list<Uuid> $runIds
     *
     * @return array<string, WorkerRunFact> run id => fact row
     */
    public function findByRunIds(Project $project, array $runIds): array
    {
        if ([] === $runIds) {
            return [];
        }

        $facts = [];
        foreach ($this->detached($project, $this->createQueryBuilder('f')
            ->andWhere('f.project = :project')
            ->andWhere('f.runId IN (:runs)')
            ->setParameter('project', $project)
            ->setParameter('runs', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $runIds))) as $fact) {
            $facts[$fact->runId->toRfc4122()] = $fact;
        }

        return $facts;
    }

    /**
     * The usage of each card's runs in one experiment, in millionths of a dollar.
     * A run that started, or that reported usage, with no cost makes the cost of
     * its card unknown. A run that started and reported no usage makes both sums
     * of its card unknown.
     *
     * @return array<string, array{costMicros: ?int, outputTokens: ?int}> card id => sums
     */
    public function sumOfExperimentByCard(Project $project, string $experiment): array
    {
        /** @var list<array{subject_id: string, cost_micros: int|string|null, output: int|string|null}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    subject_id,
                    CASE WHEN BOOL_OR(cost_micro_usd IS NULL AND (started_at IS NOT NULL OR usage_source IS NOT NULL))
                        THEN NULL ELSE SUM(cost_micro_usd) END AS cost_micros,
                    CASE WHEN BOOL_OR(usage_source IS NULL AND started_at IS NOT NULL) THEN NULL ELSE SUM(tokens_out) END AS output
                FROM bridge_worker_run_facts
                WHERE project_id = :project AND experiment = :experiment AND subject_type = 'card'
                GROUP BY subject_id
                SQL,
            [
                'project' => (string) ($project->id ?? throw new \LogicException('Project has no id.')),
                'experiment' => $experiment,
            ],
        );

        $sums = [];
        foreach ($rows as $row) {
            $sums[$row['subject_id']] = [
                'costMicros' => null === $row['cost_micros'] ? null : (int) $row['cost_micros'],
                'outputTokens' => null === $row['output'] ? null : (int) $row['output'],
            ];
        }

        return $sums;
    }

    /**
     * The writer updates rows with SQL, and a managed row cannot refresh its
     * readonly properties. Array hydration skips the identity map.
     *
     * @return list<WorkerRunFact>
     */
    private function detached(Project $project, QueryBuilder $query): array
    {
        /** @var list<array{runId: Uuid, subjectType: string, subjectId: Uuid, cardNumber: ?int, kind: WorkerRunKind, workKind: ?string, ruleId: ?string, experiment: ?string, variant: ?string, model: ?string, bridgeId: ?Uuid, outcome: WorkerRunState, startedAt: ?\DateTimeImmutable, endedAt: ?\DateTimeImmutable, receivedAt: \DateTimeImmutable, durationMs: int|string|null, costMicroUsd: int|string|null, tokensIn: int|string|null, tokensOut: int|string|null, tokensCacheRead: int|string|null, tokensCacheWrite: int|string|null, usageSource: ?WorkerRunUsageSource, toolTimeMs: int|string|null, modelTimeMs: int|string|null, toolCalls: ?int, failedCalls: ?int, longestCallMs: int|string|null, idleGapMs: int|string|null, subagentMs: int|string|null, peakContextTokens: int|string|null, meanCpuPct: float|string|null, peakMemBytes: int|string|null, peakSwapBytes: int|string|null, concurrentRuns: ?int, onBattery: ?bool}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return array_map(static fn (array $row): WorkerRunFact => new WorkerRunFact(
            runId: $row['runId'],
            project: $project,
            subjectType: $row['subjectType'],
            subjectId: $row['subjectId'],
            cardNumber: $row['cardNumber'],
            kind: $row['kind'],
            workKind: $row['workKind'],
            ruleId: $row['ruleId'],
            experiment: $row['experiment'],
            variant: $row['variant'],
            model: $row['model'],
            bridgeId: $row['bridgeId'],
            outcome: $row['outcome'],
            startedAt: $row['startedAt'],
            endedAt: $row['endedAt'],
            receivedAt: $row['receivedAt'],
            durationMs: null === $row['durationMs'] ? null : (int) $row['durationMs'],
            costMicroUsd: null === $row['costMicroUsd'] ? null : (int) $row['costMicroUsd'],
            tokensIn: null === $row['tokensIn'] ? null : (int) $row['tokensIn'],
            tokensOut: null === $row['tokensOut'] ? null : (int) $row['tokensOut'],
            tokensCacheRead: null === $row['tokensCacheRead'] ? null : (int) $row['tokensCacheRead'],
            tokensCacheWrite: null === $row['tokensCacheWrite'] ? null : (int) $row['tokensCacheWrite'],
            usageSource: $row['usageSource'],
            toolTimeMs: null === $row['toolTimeMs'] ? null : (int) $row['toolTimeMs'],
            modelTimeMs: null === $row['modelTimeMs'] ? null : (int) $row['modelTimeMs'],
            toolCalls: $row['toolCalls'],
            failedCalls: $row['failedCalls'],
            longestCallMs: null === $row['longestCallMs'] ? null : (int) $row['longestCallMs'],
            idleGapMs: null === $row['idleGapMs'] ? null : (int) $row['idleGapMs'],
            subagentMs: null === $row['subagentMs'] ? null : (int) $row['subagentMs'],
            peakContextTokens: null === $row['peakContextTokens'] ? null : (int) $row['peakContextTokens'],
            meanCpuPct: null === $row['meanCpuPct'] ? null : (float) $row['meanCpuPct'],
            peakMemBytes: null === $row['peakMemBytes'] ? null : (int) $row['peakMemBytes'],
            peakSwapBytes: null === $row['peakSwapBytes'] ? null : (int) $row['peakSwapBytes'],
            concurrentRuns: $row['concurrentRuns'],
            onBattery: $row['onBattery'],
        ), $rows);
    }
}
