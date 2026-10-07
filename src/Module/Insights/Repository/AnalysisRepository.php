<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Insights\Entity\Analysis;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    /**
     * The cost of the runs of the analysis in micro US dollars, read on each
     * call because a fact row can change after the analysis ends. Null when no
     * run has a known cost.
     */
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
