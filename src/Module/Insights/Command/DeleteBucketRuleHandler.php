<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Deletes a bucket rule, then asks for a new computation of the bucket times of its project. A rule that is already gone changes nothing. */
final readonly class DeleteBucketRuleHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InsightsBucketRuleRepository $insightsBucketRules,
        private MessageBusInterface $bus,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DeleteBucketRuleCommand $command): void
    {
        $project = $command->rule->project;
        $projectId = (string) ($project->id ?? throw new \LogicException('A stored project has an id.'));
        $ruleId = (string) ($command->rule->id ?? throw new \LogicException('A stored rule has an id.'));
        $bucket = $command->rule->bucket;

        $deleted = $this->em->wrapInTransaction(function () use ($project, $ruleId): bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            foreach ($this->insightsBucketRules->findOrderedFresh($project) as $rule) {
                if ((string) $rule->id === $ruleId) {
                    $this->em->remove($rule);
                    $this->em->flush();

                    return true;
                }
            }

            return false;
        });
        if (!$deleted) {
            return;
        }

        $this->auditor->record(
            'insights.bucket_rule_deleted',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'ruleId' => $ruleId, 'bucket' => $bucket],
            new AuditSubject('project', $projectId),
        );
        $this->bus->dispatch(new RecomputeBucketTimes($projectId));
    }
}
