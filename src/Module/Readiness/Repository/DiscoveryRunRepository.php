<?php

declare(strict_types=1);

namespace App\Module\Readiness\Repository;

use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<DiscoveryRun> */
class DiscoveryRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscoveryRun::class);
    }

    public function latestForProject(Project $project): ?DiscoveryRun
    {
        return $this->createQueryBuilder('run')
            ->where('run.project = :project')
            ->setParameter('project', $project)
            ->orderBy('run.createdAt', 'DESC')
            ->addOrderBy('run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function latestForCard(Uuid $cardId): ?DiscoveryRun
    {
        return $this->createQueryBuilder('run')
            ->where('run.card = :card')
            ->setParameter('card', $cardId, UuidType::NAME)
            ->orderBy('run.createdAt', 'DESC')
            ->addOrderBy('run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<DiscoveryRun> */
    public function findRequestedForProject(Project $project): array
    {
        return array_values($this->createQueryBuilder('run')
            ->where('run.project = :project')
            ->andWhere('run.state = :state')
            ->setParameter('project', $project)
            ->setParameter('state', DiscoveryRunState::Requested)
            ->orderBy('run.createdAt', 'ASC')
            ->addOrderBy('run.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
