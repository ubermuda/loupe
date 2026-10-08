<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\SiteReviewCheckState;
use App\Module\Forge\Entity\ForgePullRequest;
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
}
