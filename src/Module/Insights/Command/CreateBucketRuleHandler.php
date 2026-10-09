<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Service\BucketRuleWriter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Appends a bucket rule to a project, then asks for a new computation of the bucket times of its runs. */
final readonly class CreateBucketRuleHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private BucketRuleWriter $writer,
        private MessageBusInterface $bus,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CreateBucketRuleCommand $command): InsightsBucketRule
    {
        $errors = BucketRuleWriter::errors($command->pattern, $command->bucket);
        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        $project = $command->project;
        $projectId = (string) ($project->id ?? throw new \LogicException('A stored project has an id.'));

        $rule = $this->em->wrapInTransaction(function () use ($command, $project): InsightsBucketRule|DomainErrors {
            // The lock keeps two creates from sharing a position or passing the limit together.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            return $this->writer->append($project, $command->pattern, $command->bucket);
        });
        if ($rule instanceof DomainErrors) {
            throw $rule;
        }

        $this->auditor->record(
            'insights.bucket_rule_created',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'ruleId' => (string) $rule->id, 'bucket' => $rule->bucket, 'position' => $rule->position],
            new AuditSubject('project', $projectId),
        );
        $this->bus->dispatch(new RecomputeBucketTimes($projectId));

        return $rule;
    }
}
