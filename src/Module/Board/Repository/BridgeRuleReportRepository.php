<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BridgeRuleReport>
 */
class BridgeRuleReportRepository extends ServiceEntityRepository
{
    /** A bridge that loses its id makes a new one at each start, so a project keeps only its newest reports. */
    public const int KEPT_PER_PROJECT = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BridgeRuleReport::class);
    }

    /** @return list<BridgeRuleReport> newest first, at most the number a project keeps */
    public function findForProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['receivedAt' => 'DESC', 'id' => 'DESC'], self::KEPT_PER_PROJECT);
    }

    public function findOneByProjectAndBridge(Project $project, Uuid $bridgeId): ?BridgeRuleReport
    {
        return $this->findOneBy(['project' => $project, 'bridgeId' => $bridgeId]);
    }

    /** Deletes every report of the project past the newest ones it keeps. */
    public function pruneForProject(Project $project): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM board_bridge_rule_reports WHERE project_id = :project AND id NOT IN ('
            .'SELECT id FROM board_bridge_rule_reports WHERE project_id = :project ORDER BY received_at DESC, id DESC LIMIT :kept)',
            ['project' => (string) $project->id, 'kept' => self::KEPT_PER_PROJECT],
            ['kept' => ParameterType::INTEGER],
        );
    }
}
