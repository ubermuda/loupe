<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\SiteReviewCheckState;
use App\Module\Board\Workflow\CheckWanted;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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

    /** @return list<SiteReviewCheckState> the failed runs that Loupe posted on the open pull requests of the project */
    public function findPostedFailuresOnOpenPullRequests(Project $project): array
    {
        /** @var list<SiteReviewCheckState> $states */
        $states = $this->createQueryBuilder('s')
            ->join('s.pullRequest', 'pr')
            ->where('pr.project = :project AND pr.state = :open AND s.conclusion = :failure AND s.checkRunId IS NOT NULL')
            ->setParameter('project', $project)
            ->setParameter('open', PullRequestState::Open)
            ->setParameter('failure', CheckWanted::FAILURE)
            ->getQuery()
            ->getResult();

        return $states;
    }
}
