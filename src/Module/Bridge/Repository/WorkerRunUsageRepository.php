<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
