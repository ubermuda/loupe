<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\ValueObject\CostSplit;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WorkerRunUsage>
 */
class WorkerRunUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunUsage::class);
    }

    /** A DQL delete runs at once, so new rows for the same models can follow in one flush. */
    public function deleteForRun(WorkerRun $run): void
    {
        $this->createQueryBuilder('u')
            ->delete()
            ->andWhere('u.run = :run')
            ->setParameter('run', $run)
            ->getQuery()
            ->execute();
    }

    /**
     * The sums of every usage row of a card, by project and card rather than by
     * run, so the rows of a deleted run still count.
     *
     * @return array{rows: int, cost: ?string, input: int, output: int, cacheRead: int, cacheWrite: int, estimated: bool}
     */
    public function sumForCard(Project $project, Uuid $cardId): array
    {
        /** @var array{row_count: int|string, cost: string|null, input: int|string|null, output: int|string|null, cache_read: int|string|null, cache_write: int|string|null, estimated: bool|null} $row */
        $row = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    COUNT(*) AS row_count,
                    SUM(cost_usd) AS cost,
                    SUM(input_tokens) AS input,
                    SUM(output_tokens) AS output,
                    SUM(cache_read_tokens) AS cache_read,
                    SUM(cache_write_tokens) AS cache_write,
                    BOOL_OR(source = :estimated OR cost_usd IS NULL) AS estimated
                FROM bridge_worker_run_usage
                WHERE project_id = :project AND card_id = :card
                SQL,
            [
                'project' => (string) ($project->id ?? throw new \LogicException('Project has no id.')),
                'card' => (string) $cardId,
                'estimated' => WorkerRunUsageSource::Estimated->value,
            ],
        )->fetchAssociative();

        return [
            'rows' => (int) $row['row_count'],
            'cost' => $row['cost'],
            'input' => (int) $row['input'],
            'output' => (int) $row['output'],
            'cacheRead' => (int) $row['cache_read'],
            'cacheWrite' => (int) $row['cache_write'],
            'estimated' => true === $row['estimated'],
        ];
    }

    /**
     * The sums of the usage rows of each card, one row per card and part. The
     * part is the rule or the model under a split, and an empty string without
     * one. The cost is in millionths of a dollar, so the sums stay exact.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<array{cardId: string, part: string, costMicros: int, input: int, output: int, cacheRead: int, cacheWrite: int, estimated: bool}>
     */
    public function sumByCardPart(Project $project, array $cardIds, CostSplit $split, ?string $rule, ?string $model): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $part = match ($split) {
            CostSplit::None => "''",
            CostSplit::Rule => 'rule_name',
            CostSplit::Model => 'model',
        };
        $conditions = ['project_id = :project', 'card_id IN (:cards)'];
        $parameters = [
            'project' => (string) ($project->id ?? throw new \LogicException('Project has no id.')),
            'cards' => array_map(static fn (Uuid $id): string => (string) $id, $cardIds),
            'estimated' => WorkerRunUsageSource::Estimated->value,
        ];
        if (null !== $rule) {
            $conditions[] = 'rule_name = :rule';
            $parameters['rule'] = $rule;
        }
        if (null !== $model) {
            $conditions[] = 'model = :model';
            $parameters['model'] = $model;
        }

        /** @var list<array{card_id: string, part: string, cost_micros: int|string, input: int|string, output: int|string, cache_read: int|string, cache_write: int|string, estimated: bool}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            \sprintf(
                <<<'SQL'
                    SELECT
                        card_id,
                        %1$s AS part,
                        COALESCE(SUM(ROUND(cost_usd * 1000000)), 0)::bigint AS cost_micros,
                        SUM(input_tokens) AS input,
                        SUM(output_tokens) AS output,
                        SUM(cache_read_tokens) AS cache_read,
                        SUM(cache_write_tokens) AS cache_write,
                        BOOL_OR(source = :estimated OR cost_usd IS NULL) AS estimated
                    FROM bridge_worker_run_usage
                    WHERE %2$s
                    GROUP BY 1, 2
                    ORDER BY 1, 2
                    SQL,
                $part,
                implode(' AND ', $conditions),
            ),
            $parameters,
            ['cards' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'cardId' => $row['card_id'],
            'part' => $row['part'],
            'costMicros' => (int) $row['cost_micros'],
            'input' => (int) $row['input'],
            'output' => (int) $row['output'],
            'cacheRead' => (int) $row['cache_read'],
            'cacheWrite' => (int) $row['cache_write'],
            'estimated' => $row['estimated'],
        ], $rows);
    }

    /**
     * The usage of each card's runs in one experiment, in millionths of a dollar.
     * A row whose run is gone has no experiment, so it does not count. One row
     * with no price makes the cost of its card unknown. A run that started and
     * reported no usage makes both sums of its card unknown.
     *
     * @return array<string, array{costMicros: ?int, outputTokens: ?int}> card id => sums
     */
    public function sumOfExperimentByCard(Project $project, string $experiment): array
    {
        /** @var list<array{card_id: string, cost_micros: int|string|null, output: int|string|null}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    r.card_id,
                    CASE WHEN BOOL_OR(r.usage_source IS NULL AND r.state NOT IN (:unstarted)) OR BOOL_OR(u.run_id IS NOT NULL AND u.cost_usd IS NULL)
                        THEN NULL ELSE SUM(ROUND(u.cost_usd * 1000000))::bigint END AS cost_micros,
                    CASE WHEN BOOL_OR(r.usage_source IS NULL AND r.state NOT IN (:unstarted)) THEN NULL ELSE SUM(u.output_tokens) END AS output
                FROM bridge_worker_runs r
                LEFT JOIN bridge_worker_run_usage u ON u.run_id = r.id AND u.project_id = :project
                WHERE r.project_id = :project AND r.experiment = :experiment
                GROUP BY r.card_id
                SQL,
            [
                'project' => (string) ($project->id ?? throw new \LogicException('Project has no id.')),
                'experiment' => $experiment,
                'unstarted' => array_map(static fn (WorkerRunState $state): string => $state->value, [
                    WorkerRunState::Queued, WorkerRunState::Replaced, WorkerRunState::Skipped, WorkerRunState::Dropped, WorkerRunState::NotStarted,
                ]),
            ],
            ['unstarted' => ArrayParameterType::STRING],
        );

        $sums = [];
        foreach ($rows as $row) {
            $sums[$row['card_id']] = [
                'costMicros' => null === $row['cost_micros'] ? null : (int) $row['cost_micros'],
                'outputTokens' => null === $row['output'] ? null : (int) $row['output'],
            ];
        }

        return $sums;
    }

    /**
     * The rule names and the models of every usage row of the project, sorted.
     *
     * @return array{rules: list<string>, models: list<string>}
     */
    public function rulesAndModelsOf(Project $project): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $parameters = ['project' => (string) ($project->id ?? throw new \LogicException('Project has no id.'))];

        /** @var list<string> $rules */
        $rules = $connection->fetchFirstColumn('SELECT DISTINCT rule_name FROM bridge_worker_run_usage WHERE project_id = :project ORDER BY rule_name', $parameters);
        /** @var list<string> $models */
        $models = $connection->fetchFirstColumn('SELECT DISTINCT model FROM bridge_worker_run_usage WHERE project_id = :project ORDER BY model', $parameters);

        return ['rules' => $rules, 'models' => $models];
    }

    /**
     * Every usage row on every project the user owns, for the account data
     * export. It includes the rows whose run the retention sweep deleted.
     *
     * @return list<WorkerRunUsage>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('u')
            ->join('u.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
