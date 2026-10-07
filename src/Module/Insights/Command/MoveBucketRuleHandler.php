<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Insights\Entity\BucketRuleDirection;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Swaps a bucket rule with its neighbour. The first rule cannot move up and the last cannot move down, so those moves change nothing. */
final readonly class MoveBucketRuleHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InsightsBucketRuleRepository $insightsBucketRules,
        private MessageBusInterface $bus,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(MoveBucketRuleCommand $command): void
    {
        $project = $command->rule->project;
        $projectId = (string) ($project->id ?? throw new \LogicException('A stored project has an id.'));
        $ruleId = (string) ($command->rule->id ?? throw new \LogicException('A stored rule has an id.'));

        $moved = $this->em->wrapInTransaction(function () use ($command, $project, $ruleId): bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $ordered = $this->insightsBucketRules->findOrderedFresh($project);
            foreach ($ordered as $index => $rule) {
                if ((string) $rule->id !== $ruleId) {
                    continue;
                }
                $neighbour = $ordered[BucketRuleDirection::Up === $command->direction ? $index - 1 : $index + 1] ?? null;
                if (null === $neighbour) {
                    return false;
                }
                [$rule->position, $neighbour->position] = [$neighbour->position, $rule->position];
                $this->em->flush();

                return true;
            }

            return false;
        });
        if (!$moved) {
            return;
        }

        $this->auditor->record(
            'insights.bucket_rule_moved',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'ruleId' => $ruleId, 'direction' => $command->direction->value],
            new AuditSubject('project', $projectId),
        );
        $this->bus->dispatch(new RecomputeBucketTimes($projectId));
    }
}
