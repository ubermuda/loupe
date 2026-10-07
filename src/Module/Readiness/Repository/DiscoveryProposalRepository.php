<?php

declare(strict_types=1);

namespace App\Module\Readiness\Repository;

use App\Module\Readiness\Entity\DiscoveryProposal;
use App\Module\Readiness\Entity\DiscoveryRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DiscoveryProposal> */
class DiscoveryProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscoveryProposal::class);
    }

    /** @return list<DiscoveryProposal> in the order the report lists them */
    public function findForRun(DiscoveryRun $run): array
    {
        return array_values($this->createQueryBuilder('proposal')
            ->where('proposal.run = :run')
            ->setParameter('run', $run)
            ->orderBy('proposal.position', 'ASC')
            ->addOrderBy('proposal.key', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
