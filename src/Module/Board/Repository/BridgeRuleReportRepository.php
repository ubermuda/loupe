<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BridgeRuleReport>
 */
class BridgeRuleReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BridgeRuleReport::class);
    }

    /** @return list<BridgeRuleReport> newest first */
    public function findForProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['receivedAt' => 'DESC']);
    }

    public function findOneByProjectAndBridge(Project $project, Uuid $bridgeId): ?BridgeRuleReport
    {
        return $this->findOneBy(['project' => $project, 'bridgeId' => $bridgeId]);
    }
}
