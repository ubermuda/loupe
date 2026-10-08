<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Account\Entity\User;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InsightsBucketRule> */
class InsightsBucketRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InsightsBucketRule::class);
    }

    /** @return list<InsightsBucketRule> in order of position */
    public function findOrdered(Project $project): array
    {
        return array_values($this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->setParameter('project', $project)
            ->orderBy('r.position', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Read fresh even when a rule is already managed, for a caller that holds the project lock.
     *
     * @return list<InsightsBucketRule> in order of position
     */
    public function findOrderedFresh(Project $project): array
    {
        return array_values($this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->setParameter('project', $project)
            ->orderBy('r.position', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult());
    }

    /** @return list<InsightsBucketRule> the rules of the projects the user owns, by project, then in order of position */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('r')
            ->join('r.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->addOrderBy('r.position', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** Null for a malformed id and for a rule of another project alike. */
    public function findOneByIdAndProjectId(string $ruleId, string $projectId): ?InsightsBucketRule
    {
        if (!Uuid::isValid($ruleId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.id = :ruleId')
            ->andWhere('r.project = :projectId')
            ->setParameter('ruleId', Uuid::fromString($ruleId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** The position after the last rule of the project. */
    public function nextPosition(Project $project): int
    {
        $last = $this->createQueryBuilder('r')
            ->select('MAX(r.position)')
            ->andWhere('r.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $last ? 0 : (int) $last + 1;
    }
}
