<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\SiteReviewCheckState;
use App\Module\Board\Workflow\CheckWanted;
use App\Module\Forge\Entity\ForgePullRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<SiteReviewCheckState> */
class SiteReviewCheckStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteReviewCheckState::class);
    }

    public function findOneByPullRequest(ForgePullRequest $pullRequest): ?SiteReviewCheckState
    {
        return $this->findOneBy(['pullRequest' => $pullRequest]);
    }

    /** The posted failure of the pull request with this key, or null when Loupe holds none that it can still change. */
    public function findPostedFailureByKey(Uuid $projectId, string $forge, string $repository, int $number): ?SiteReviewCheckState
    {
        return $this->createQueryBuilder('s')
            ->join('s.pullRequest', 'pr')
            ->where('pr.project = :project AND pr.forge = :forge AND pr.repository = :repository AND pr.number = :number')
            ->andWhere('s.conclusion = :failure AND s.checkRunId IS NOT NULL')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('forge', $forge)
            ->setParameter('repository', mb_strtolower($repository))
            ->setParameter('number', $number)
            ->setParameter('failure', CheckWanted::FAILURE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The state of the pull request with this key when it names a posted run, whatever the conclusion. */
    public function findPostedRunByKey(Uuid $projectId, string $forge, string $repository, int $number): ?SiteReviewCheckState
    {
        return $this->createQueryBuilder('s')
            ->join('s.pullRequest', 'pr')
            ->where('pr.project = :project AND pr.forge = :forge AND pr.repository = :repository AND pr.number = :number AND s.checkRunId IS NOT NULL')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('forge', $forge)
            ->setParameter('repository', mb_strtolower($repository))
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
