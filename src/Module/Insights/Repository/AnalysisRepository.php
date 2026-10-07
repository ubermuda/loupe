<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Insights\Entity\Analysis;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Analysis> */
class AnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Analysis::class);
    }

    /** Locked until the transaction ends, and read fresh even when the analysis is already managed. */
    public function findOneLocked(Uuid $id): ?Analysis
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** Null for a malformed id and for an analysis of another project alike. */
    public function findOneByIdAndProject(string $id, Project $project): ?Analysis
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'project' => $project]);
    }

    /** @return list<Analysis> newest first */
    public function findByProject(Project $project, ?int $limit = null): array
    {
        return array_values($this->createQueryBuilder('a')
            ->andWhere('a.project = :project')
            ->setParameter('project', $project)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /**
     * The cost of the runs of the analysis in micro US dollars, read on each
     * call because a fact row can change after the analysis ends. Null when no
     * run has a known cost.
     */
    /**
     * The cost of each analysis in one query, keyed by analysis id. An analysis with no known run cost is missing.
     *
     * @param list<Analysis> $analyses
     *
     * @return array<string, int>
     */
    public function costsOf(Project $project, array $analyses): array
    {
        if ([] === $analyses) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT subject_id, SUM(cost_micro_usd) AS cost FROM bridge_worker_run_facts WHERE project_id = :project AND subject_type = :type AND subject_id IN (:ids) GROUP BY subject_id HAVING SUM(cost_micro_usd) IS NOT NULL',
            [
                'project' => (string) ($project->id ?? throw new \LogicException('A stored project has an id.')),
                'type' => Analysis::SUBJECT_TYPE,
                'ids' => array_map(static fn (Analysis $analysis): string => (string) ($analysis->id ?? throw new \LogicException('A stored analysis has an id.')), $analyses),
            ],
            ['ids' => ArrayParameterType::STRING],
        );
        $costs = [];
        foreach ($rows as $row) {
            $costs[(string) $row['subject_id']] = (int) $row['cost'];
        }

        return $costs;
    }

    public function costOf(Analysis $analysis): ?int
    {
        $sum = $this->getEntityManager()->createQueryBuilder()
            ->select('SUM(f.costMicroUsd)')
            ->from(WorkerRunFact::class, 'f')
            ->andWhere('f.project = :project')
            ->andWhere('f.subjectType = :type')
            ->andWhere('f.subjectId = :id')
            ->setParameter('project', $analysis->project)
            ->setParameter('type', Analysis::SUBJECT_TYPE)
            ->setParameter('id', $analysis->id ?? throw new \LogicException('A stored analysis has an id.'), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $sum ? null : (int) $sum;
    }

    /**
     * Every analysis on every project the user owns, for the account data export.
     *
     * @return list<Analysis>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('a')
            ->join('a.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
