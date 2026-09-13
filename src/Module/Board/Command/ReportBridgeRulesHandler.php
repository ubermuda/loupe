<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Replaces one bridge's report for one project, and drops the project's
 * oldest reports past the number it keeps. Null when the owner has no project
 * by that handle.
 */
final readonly class ReportBridgeRulesHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private BridgeRuleReportRepository $bridgeRuleReports,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReportBridgeRulesCommand $command): ?BridgeRuleReport
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return null;
        }

        // The project lock serialises two reports from one bridge, which would
        // otherwise both insert and trip the unique index.
        $report = $this->em->wrapInTransaction(function () use ($command, $project): BridgeRuleReport {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $now = $this->clock->now();
            $report = $this->bridgeRuleReports->findOneByProjectAndBridge($project, $command->bridgeId);
            if (null === $report) {
                $report = new BridgeRuleReport($project, $command->bridgeId, $command->rules, $now);
                $this->em->persist($report);
            } else {
                $report->rules = $command->rules;
                $report->receivedAt = $now;
            }
            $this->em->flush();
            $this->bridgeRuleReports->pruneForProject($project);

            return $report;
        });

        $this->auditor->record(
            'board.bridge_rules_reported',
            AuditOutcome::Success,
            [
                'projectId' => (string) $project->id,
                'bridgeId' => (string) $command->bridgeId,
                'rules' => \count($command->rules),
                'deadRules' => \count(array_filter(
                    $command->rules,
                    static fn (array $rule): bool => BridgeRuleReport::STATE_DEAD === $rule['state'],
                )),
            ],
            new AuditSubject('bridge_rule_report', (string) $report->id),
        );

        return $report;
    }
}
